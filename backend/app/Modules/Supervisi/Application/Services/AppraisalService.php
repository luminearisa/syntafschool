<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\AppraisalPeriodType;
use App\Modules\Supervisi\Domain\Enums\AppraisalStatus;
use App\Modules\Supervisi\Domain\Enums\AppraisalType;
use App\Modules\Supervisi\Models\SupAppraisal;
use App\Modules\Supervisi\Models\SupAppraisalScore;
use App\Modules\Supervisi\Models\SupAppraisalSource;
use App\Modules\Supervisi\Models\SupGradeScale;
use App\Modules\Supervisi\Models\SupInstrument;
use App\Modules\Supervisi\Models\SupInstrumentVersion;
use App\Modules\Supervisi\Models\SupVisit;
use App\Modules\Supervisi\Application\Support\SearchTerm;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penilaian kinerja: PKG, PKG BK, NKKS, tinjauan pegawai berbobot + 360°,
 * kuadran SKP, dan kaitan ke RTL/PIP.
 *
 * Angka mentah (skor tiap indikator) berasal dari penilai; seluruh konversi,
 * koreksi kehadiran, predikat, dan persentase angka kredit dihitung di sini
 * dan disimpan bersama `snapshot` rumusnya.
 */
class AppraisalService
{
    public function __construct(
        protected ScoringEngine $engine,
        protected AppraisalCalculator $calculator,
        protected readonly SupervisiSettings $settings,
    ) {}

    // ------------------------------------------------------------------
    // Daftar & detail
    // ------------------------------------------------------------------

    /**
     * Batas pandang: pegawai melihat penilaian dirinya sendiri, penilai
     * melihat yang ia nilai, pemegang izin tata kelola melihat semuanya.
     *
     * @return Collection<int,SupAppraisal>
     */
    public function list(array $filters, User $actor): Collection
    {
        $query = SupAppraisal::query()
            ->with(['supervisee:id,full_name,position', 'assessor:id,full_name'])
            ->orderByDesc('period_start')
            ->orderByDesc('id');

        if (! $this->canViewAll($actor)) {
            $employeeId = $this->employeeIdFor($actor);

            $query->where(function ($builder) use ($employeeId, $actor): void {
                if ($employeeId !== null) {
                    $builder->where('supervisee_employee_id', $employeeId)
                        ->orWhere('assessor_employee_id', $employeeId)
                        ->orWhere('reviewer_employee_id', $employeeId);

                    return;
                }

                $builder->where('created_by', $actor->id);
            });
        }

        if (! empty($filters['type'])) {
            $query->where('appraisal_type', $filters['type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('supervisee_employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['year'])) {
            $query->whereYear('period_start', (int) $filters['year']);
        }

        if (($term = SearchTerm::like($filters['search'] ?? null)) !== null) {
            $query->whereHas('supervisee', fn ($builder) => $builder->where('full_name', 'like', $term));
        }

        return $query->limit((int) ($filters['limit'] ?? 100))->get();
    }

    /** @return array<string,mixed> */
    public function detail(SupAppraisal $appraisal, User $actor): array
    {
        $appraisal->load([
            'scores.indicator:id,code,statement,weight,input_type',
            'sources',
            'supervisee:id,full_name,position',
            'assessor:id,full_name',
            'reviewer:id,full_name',
        ]);

        $version = $appraisal->instrument_version_id === null ? null : $this->version($appraisal->instrument_version_id);

        return [
            'appraisal' => $this->present($appraisal),
            'instrument' => $version === null ? null : [
                'id' => $version->id,
                'code' => $version->instrument?->code,
                'name' => $version->instrument?->name,
                'version' => $version->version,
                'scoring_mode' => $version->scoring_mode->value,
                'max_score' => $version->max_score,
                'grade_scale' => $version->gradeScale === null ? null : [
                    'code' => $version->gradeScale->code,
                    'name' => $version->gradeScale->name,
                    'scale_type' => $version->gradeScale->scale_type,
                    'bands' => $version->gradeScale->bands,
                ],
            ],
            'indicators' => $version === null ? [] : $this->indicatorRows($version),
            'sources' => $this->sourceRows($appraisal),
            'source_weights' => $this->sourceWeights(),
            'abilities' => $this->abilities($appraisal, $actor),
        ];
    }

    // ------------------------------------------------------------------
    // Siklus hidup dokumen
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $payload */
    public function create(array $payload, User $actor): SupAppraisal
    {
        $type = AppraisalType::from((string) $payload['appraisal_type']);

        $version = isset($payload['instrument_version_id'])
            ? $this->version((int) $payload['instrument_version_id'])
            : $this->resolveVersion($type);

        if ($version === null && $type !== AppraisalType::SKP_PERIODIK) {
            throw ValidationException::withMessages([
                'instrument_version_id' => "Tidak ada instrumen terbit untuk jenis penilaian {$type->value}. Terbitkan instrumennya lebih dulu.",
            ]);
        }

        $employeeId = (int) $payload['supervisee_employee_id'];
        $assessorId = isset($payload['assessor_employee_id'])
            ? (int) $payload['assessor_employee_id']
            : $this->employeeIdFor($actor);

        if ($assessorId !== null && $assessorId === $employeeId) {
            throw ValidationException::withMessages([
                'assessor_employee_id' => 'Penilai tidak boleh menilai dirinya sendiri.',
            ]);
        }

        return SupAppraisal::query()->create([
            'school_id' => $actor->school_id,
            'supervisee_employee_id' => $employeeId,
            'appraisal_type' => $type,
            'period_type' => AppraisalPeriodType::from((string) ($payload['period_type'] ?? 'SEMESTER')),
            'period_start' => $payload['period_start'] ?? null,
            'period_end' => $payload['period_end'] ?? null,
            'academic_year_id' => $payload['academic_year_id'] ?? null,
            'instrument_version_id' => $version?->id,
            'assessor_employee_id' => $assessorId,
            'reviewer_employee_id' => $payload['reviewer_employee_id'] ?? null,
            'status' => AppraisalStatus::DRAFT,
            'created_by' => $actor->id,
        ]);
    }

    /** @param array<string,mixed> $payload */
    public function update(SupAppraisal $appraisal, array $payload, User $actor): SupAppraisal
    {
        $this->assertEditable($appraisal);

        $appraisal->fill(array_filter([
            'period_type' => isset($payload['period_type']) ? AppraisalPeriodType::from((string) $payload['period_type']) : null,
            'period_start' => $payload['period_start'] ?? null,
            'period_end' => $payload['period_end'] ?? null,
            'assessor_employee_id' => $payload['assessor_employee_id'] ?? null,
            'reviewer_employee_id' => $payload['reviewer_employee_id'] ?? null,
        ], fn ($value) => $value !== null))->save();

        return $appraisal->refresh();
    }

    // ------------------------------------------------------------------
    // Skor & perhitungan
    // ------------------------------------------------------------------

    /**
     * Simpan skor per indikator.
     *
     * Penilai boleh mengirim `option_id` (rubrik), `score` langsung (mis. PKG
     * 1–4), `value_number`, atau `value_text` — namun nilainya divalidasi
     * terhadap definisi indikator, dan skor akhir selalu dihitung mesin.
     *
     * @param  list<array<string,mixed>>  $scores
     */
    public function saveScores(SupAppraisal $appraisal, array $scores, User $actor): SupAppraisal
    {
        $this->assertEditable($appraisal);
        $this->assertCanScore($actor, $appraisal);

        $version = $this->versionOrFail($appraisal);
        $indicators = $version->loadMissing('sections.indicators.options')->indicators->keyBy('id');

        DB::transaction(function () use ($appraisal, $scores, $indicators): void {
            foreach ($scores as $row) {
                $indicatorId = (int) ($row['indicator_id'] ?? 0);
                $indicator = $indicators->get($indicatorId);

                if ($indicator === null) {
                    throw ValidationException::withMessages([
                        'scores' => "Indikator {$indicatorId} bukan bagian dari instrumen penilaian ini.",
                    ]);
                }

                $answer = [
                    'option_id' => $row['option_id'] ?? null,
                    'score' => $row['score'] ?? null,
                    'value_number' => $row['value_number'] ?? null,
                    'value_text' => $row['value_text'] ?? null,
                    'value_long_text' => $row['value_long_text'] ?? null,
                    'value_json' => $row['value_json'] ?? null,
                    'is_na' => (bool) ($row['is_na'] ?? false),
                ];

                $this->assertEntryAllowed($indicator, $answer);
                $computed = $this->engine->indicatorScore($indicator, $answer);

                SupAppraisalScore::query()->updateOrCreate(
                    ['appraisal_id' => $appraisal->id, 'indicator_id' => $indicatorId],
                    ['score' => $computed, 'comment' => $row['comment'] ?? null],
                );
            }
        });

        return $appraisal->refresh();
    }

    /**
     * Hitung nilai akhir dokumen penilaian dan simpan seluruh langkahnya.
     */
    public function calculate(SupAppraisal $appraisal, User $actor, array $inputs = []): SupAppraisal
    {
        $this->assertEditable($appraisal);

        if (array_key_exists('attendance_absence_days', $inputs)) {
            $appraisal->attendance_absence_days = $inputs['attendance_absence_days'];
        }

        if (array_key_exists('result_rating', $inputs)) {
            $appraisal->result_rating = $inputs['result_rating'];
        }

        if (array_key_exists('behavior_rating', $inputs)) {
            $appraisal->behavior_rating = $inputs['behavior_rating'];
        }

        $version = $this->optionalVersion($appraisal);

        if ($version === null && $appraisal->appraisal_type !== AppraisalType::SKP_PERIODIK) {
            throw ValidationException::withMessages([
                'instrument_version_id' => 'Dokumen ini belum memiliki instrumen penilaian.',
            ]);
        }

        $appraisal->load('scores');
        $entries = [];

        foreach ($appraisal->scores as $score) {
            $entries[(int) $score->indicator_id] = [
                'score' => $score->score === null ? null : (float) $score->score,
                'is_na' => false,
            ];
        }

        $summary = $version === null ? ScoringEngine::EMPTY_RESULT : $this->engine->scoreAnswers($version, $entries);
        $converted = $summary['final'];

        // SKP periodik: rating dapat diturunkan dari persentase nilai bila diisi
        // (di atas 100% / 90–100% / di bawah 90% menurut pedoman manajemen kinerja).
        $ratingPercent = [
            'result' => $inputs['result_percent'] ?? null,
            'behavior' => $inputs['behavior_percent'] ?? null,
        ];

        if ($version === null && $appraisal->appraisal_type === AppraisalType::SKP_PERIODIK) {
            $thresholds = (array) ($this->settings->get('skp_rating_thresholds') ?: AppraisalCalculator::DEFAULT_THRESHOLDS);

            if ($ratingPercent['result'] !== null && $appraisal->result_rating === null) {
                $appraisal->result_rating = $this->calculator->ratingFromScore((float) $ratingPercent['result'], $thresholds);
            }

            if ($ratingPercent['behavior'] !== null && $appraisal->behavior_rating === null) {
                $appraisal->behavior_rating = $this->calculator->ratingFromScore((float) $ratingPercent['behavior'], $thresholds);
            }

            if ($ratingPercent['result'] !== null && $ratingPercent['behavior'] !== null) {
                $converted = round(((float) $ratingPercent['result'] + (float) $ratingPercent['behavior']) / 2, 2);
            }
        }

        // Koreksi kehadiran (Permendikbud 35/2010) — hanya bila diaktifkan.
        $attendance = null;
        $afterAttendance = $converted;

        if ($appraisal->attendance_absence_days !== null && $this->settings->bool('attendance_correction_active')) {
            $attendance = $this->calculator->attendanceCorrection(
                (float) $converted,
                (int) $appraisal->attendance_absence_days,
                $this->settings->int('attendance_working_days') ?: AppraisalCalculator::WORKING_DAYS,
            );
            $afterAttendance = $attendance['corrected'];
        }

        // Kontribusi supervisi ke penilaian kinerja (bila sekolah mengaktifkan).
        $supervision = $this->supervisionContribution($appraisal, (float) $afterAttendance);
        $final = $supervision['final'];

        // Penilaian multi-sumber (360°): dipakai bila ada skor sumber terkirim.
        try {
            $multiSource = $this->calculator->multiSource(
                $appraisal->sources->map(fn (SupAppraisalSource $source) => [
                    'source' => $source->source,
                    'score' => $source->score === null ? null : (float) $source->score,
                    'weight' => (float) $source->weight,
                ])->all(),
                $this->settings->bool('appraisal_normalize_weights'),
            );
        } catch (InvalidArgumentException $exception) {
            // Bobot yang tidak sahih dilaporkan sebagai kesalahan isian (422),
            // bukan kesalahan server.
            throw ValidationException::withMessages(['sources' => $exception->getMessage()]);
        }

        if ($multiSource['score'] !== null) {
            $final = $multiSource['score'];
        }

        // Predikat: kuadran SKP bila rating diisi, selain itu skala predikat.
        $skp = null;

        if ($appraisal->appraisal_type === AppraisalType::SKP_PERIODIK
            && $appraisal->result_rating !== null && $appraisal->behavior_rating !== null) {
            try {
                $skp = $this->calculator->skpPredicate(
                    (string) $appraisal->result_rating,
                    (string) $appraisal->behavior_rating,
                    $this->matrix() ?: null,
                    (array) ($this->settings->get('skp_predicate_credit') ?: []),
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['behavior_rating' => $exception->getMessage()]);
            }
        }

        $scale = $this->scaleFor($appraisal);
        $band = $scale?->bandFor($final, $scale->scale_type);

        $snapshot = [
            'formula' => 'supervisi-appraisal-v1',
            'type' => $appraisal->appraisal_type->value,
            'instrument' => $version === null ? null : [
                'version_id' => $version->id,
                'code' => $version->instrument?->code,
                'version' => $version->version,
                'max_score' => $version->max_score,
                'scoring_mode' => $version->scoring_mode->value,
            ],
            'steps' => [
                'rating_percent' => $ratingPercent,
                'raw' => $summary['raw'],
                'max' => $summary['max'],
                'converted' => $converted,
                'attendance' => $attendance,
                'after_attendance' => $afterAttendance,
                'supervision' => $supervision,
                'multi_source' => $multiSource,
                'final' => $final,
            ],
            'entries' => $entries,
            'band' => $band,
            'credit_percent' => $band['credit_percent'] ?? ($skp['credit_percent'] ?? null),
            'skp' => $skp,
            'scale' => $scale === null ? null : ['code' => $scale->code, 'name' => $scale->name],
            'final_predicate' => $skp['predicate'] ?? null,
            'calculated_at' => Carbon::now()->toIso8601String(),
            'calculated_by' => $actor->id,
        ];

        $appraisal->forceFill([
            'raw_score' => $summary['raw'],
            'max_score' => $summary['max'],
            'final_score' => $final,
            'grade_band' => $band['code'] ?? null,
            'grade_label' => $skp['label'] ?? ($band['label'] ?? null),
            'credit_percent' => $snapshot['credit_percent'],
            'attendance_correction_percent' => $attendance['percent'] ?? null,
            'supervision_weight' => $supervision['weight'],
            'supervision_contribution' => $supervision['contribution'],
            'final_predicate' => $skp['predicate'] ?? null,
            'snapshot' => $snapshot,
            'status' => $appraisal->status === AppraisalStatus::DRAFT ? AppraisalStatus::SUBMITTED : $appraisal->status,
        ])->save();

        return $appraisal->refresh();
    }

    /** Simpan bobot & skor penilaian multi-sumber (360°). */
    public function saveSources(SupAppraisal $appraisal, array $sources, User $actor): SupAppraisal
    {
        $this->assertEditable($appraisal);

        foreach ($sources as $row) {
            $source = strtoupper((string) ($row['source'] ?? ''));

            if (! in_array($source, ['SELF', 'SUPERVISOR', 'PEER', 'SUBORDINATE', 'STUDENT', 'PARENT'], true)) {
                throw ValidationException::withMessages(['sources' => "Sumber penilaian {$source} tidak dikenal."]);
            }

            SupAppraisalSource::query()->updateOrCreate(
                [
                    'appraisal_id' => $appraisal->id,
                    'source' => $source,
                    'rater_employee_id' => $row['rater_employee_id'] ?? null,
                ],
                [
                    'weight' => $row['weight'] ?? 0,
                    'score' => $row['score'] ?? null,
                    'comment' => $row['comment'] ?? null,
                    'submitted_at' => ($row['score'] ?? null) === null ? null : Carbon::now(),
                ],
            );
        }

        return $appraisal->refresh();
    }

    public function publish(SupAppraisal $appraisal, User $actor): SupAppraisal
    {
        if ($appraisal->final_score === null) {
            throw ValidationException::withMessages([
                'final_score' => 'Hitung nilai terlebih dahulu sebelum menerbitkan hasil.',
            ]);
        }

        if ($appraisal->status === AppraisalStatus::PUBLISHED) {
            throw ValidationException::withMessages(['status' => 'Hasil penilaian ini sudah diterbitkan.']);
        }

        $appraisal->forceFill([
            'status' => AppraisalStatus::PUBLISHED,
            'published_at' => Carbon::now(),
        ])->save();

        return $appraisal->refresh();
    }

    /** Catat koreksi pasca-publikasi tanpa menghapus dokumen. */
    public function reopen(SupAppraisal $appraisal, User $actor, string $reason): SupAppraisal
    {
        if ($appraisal->status !== AppraisalStatus::PUBLISHED) {
            throw ValidationException::withMessages(['status' => 'Hanya hasil yang sudah terbit yang dapat dibuka ulang.']);
        }

        $snapshot = $appraisal->snapshot ?? [];
        $snapshot['reopenings'] = [
            ...($snapshot['reopenings'] ?? []),
            ['reason' => $reason, 'by' => $actor->id, 'at' => Carbon::now()->toIso8601String()],
        ];

        $appraisal->forceFill([
            'status' => AppraisalStatus::DRAFT,
            'published_at' => null,
            'snapshot' => $snapshot,
        ])->save();

        return $appraisal->refresh();
    }

    // ------------------------------------------------------------------
    // Bantu
    // ------------------------------------------------------------------

    /** Instrumen terbit bawaan untuk sebuah jenis penilaian. */
    public function resolveVersion(AppraisalType $type): ?SupInstrumentVersion
    {
        $code = $this->calculator->defaultInstruments()[$type->value]['code'] ?? null;

        if ($code === null) {
            return null;
        }

        $instrument = SupInstrument::query()->where('code', $code)->first();

        return $instrument?->current_version_id === null
            ? null
            : $this->version((int) $instrument->current_version_id);
    }

    protected function optionalVersion(SupAppraisal $appraisal): ?SupInstrumentVersion
    {
        return $appraisal->instrument_version_id === null ? null : $this->version((int) $appraisal->instrument_version_id);
    }

    protected function versionOrFail(SupAppraisal $appraisal): SupInstrumentVersion
    {
        if ($appraisal->instrument_version_id === null) {
            throw ValidationException::withMessages([
                'instrument_version_id' => 'Dokumen ini belum memiliki instrumen penilaian.',
            ]);
        }

        return $this->version((int) $appraisal->instrument_version_id);
    }

    protected function version(int $versionId): SupInstrumentVersion
    {
        return SupInstrumentVersion::query()
            ->with(['instrument:id,code,name', 'gradeScale', 'sections.indicators.options'])
            ->findOrFail($versionId);
    }

    /**
     * Skala predikat: PKG/NKKS memakai skala sebutan & angka kredit, sisanya
     * mengikuti skala versi instrumen.
     */
    protected function scaleFor(SupAppraisal $appraisal): ?SupGradeScale
    {
        if (in_array($appraisal->appraisal_type, [AppraisalType::PKG, AppraisalType::PKG_BK, AppraisalType::NKKS], true)) {
            $code = (string) ($this->settings->get('pkg_credit_scale_code') ?: 'pkg_sebutan');

            return SupGradeScale::query()->where('code', $code)->first()
                ?? $this->optionalVersion($appraisal)?->gradeScale;
        }

        return $this->optionalVersion($appraisal)?->gradeScale;
    }

    /**
     * Sebagian sekolah menghitung supervisi sebagai salah satu komponen
     * penilaian kinerja (`supervision_weight_percent`).
     *
     * @return array{weight:float|null,average:float|null,contribution:float|null,final:float,visits:int}
     */
    protected function supervisionContribution(SupAppraisal $appraisal, float $base): array
    {
        $weight = (float) ($this->settings->get('supervision_weight_percent') ?? 0);

        if ($weight <= 0 || $weight >= 100) {
            return ['weight' => $weight > 0 ? $weight : null, 'average' => null, 'contribution' => null, 'final' => $base, 'visits' => 0];
        }

        $visits = SupVisit::query()
            ->where('supervisee_employee_id', $appraisal->supervisee_employee_id)
            ->whereNotNull('finalized_at')
            ->when($appraisal->period_start !== null, fn ($query) => $query->whereDate('finalized_at', '>=', $appraisal->period_start))
            ->when($appraisal->period_end !== null, fn ($query) => $query->whereDate('finalized_at', '<=', $appraisal->period_end))
            ->pluck('final_score')
            ->filter(fn ($score) => $score !== null);

        if ($visits->isEmpty()) {
            return ['weight' => $weight, 'average' => null, 'contribution' => null, 'final' => $base, 'visits' => 0];
        }

        $average = round((float) $visits->avg(), 2);
        $contribution = round($average * $weight / 100, 2);
        $final = round($base * (100 - $weight) / 100 + $contribution, 2);

        return ['weight' => $weight, 'average' => $average, 'contribution' => $contribution, 'final' => $final, 'visits' => $visits->count()];
    }

    /** @param array<string,mixed> $answer */
    protected function assertEntryAllowed(object $indicator, array $answer): void
    {
        if (($answer['is_na'] ?? false) === true) {
            if (! $indicator->allow_na) {
                throw ValidationException::withMessages([
                    'scores' => "Indikator \"{$indicator->statement}\" tidak boleh ditandai tidak relevan.",
                ]);
            }

            return;
        }

        if ($answer['option_id'] !== null && ! $indicator->options->contains('id', (int) $answer['option_id'])) {
            throw ValidationException::withMessages([
                'scores' => "Pilihan rubrik tidak dikenal pada indikator \"{$indicator->statement}\".",
            ]);
        }

        if ($answer['score'] !== null) {
            $score = (float) $answer['score'];
            $allowed = $indicator->options->pluck('score')->map(fn ($value) => (float) $value)->all();

            if ($allowed !== []) {
                // Indikator rubrik: skor wajib persis salah satu nilai pilihan.
                $valid = in_array(round($score, 2), array_map(fn ($value) => round($value, 2), $allowed), true);

                if (! $valid) {
                    throw ValidationException::withMessages([
                        'scores' => "Skor {$score} bukan salah satu pilihan pada indikator \"{$indicator->statement}\" "
                            . '(pilihan: ' . implode(', ', $allowed) . ').',
                    ]);
                }
            } else {
                // Indikator angka: wajib dalam rentang indikator.
                $min = $indicator->score_min === null ? null : (float) $indicator->score_min;
                $max = $indicator->score_max === null ? null : (float) $indicator->score_max;

                if (($min !== null && $score < $min) || ($max !== null && $score > $max)) {
                    throw ValidationException::withMessages([
                        'scores' => "Skor {$score} di luar rentang indikator \"{$indicator->statement}\" ({$min}–{$max}).",
                    ]);
                }
            }
        }

        if ($answer['value_number'] !== null) {
            $value = (float) $answer['value_number'];
            $min = $indicator->score_min === null ? null : (float) $indicator->score_min;
            $max = $indicator->score_max === null ? null : (float) $indicator->score_max;
            $step = $indicator->score_step === null ? null : (float) $indicator->score_step;

            if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
                throw ValidationException::withMessages([
                    'scores' => "Nilai {$value} di luar rentang indikator \"{$indicator->statement}\" ({$min}–{$max}).",
                ]);
            }

            if ($step !== null && $step > 0 && abs(fmod($value - ($min ?? 0), $step)) > 0.0001) {
                throw ValidationException::withMessages([
                    'scores' => "Nilai harus kelipatan {$step} pada indikator \"{$indicator->statement}\".",
                ]);
            }
        }
    }

    protected function assertEditable(SupAppraisal $appraisal): void
    {
        if (in_array($appraisal->status, [AppraisalStatus::PUBLISHED, AppraisalStatus::ARCHIVED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Hasil yang sudah terbit tidak dapat diubah. Buka ulang dokumen lebih dulu.',
            ]);
        }
    }

    protected function assertCanScore(User $actor, SupAppraisal $appraisal): void
    {
        if ($this->canViewAll($actor) || $actor->can('supervisi.appraisal.calculate')) {
            return;
        }

        if ($appraisal->assessor_employee_id !== null && $appraisal->assessor_employee_id === $this->employeeIdFor($actor)) {
            return;
        }

        abort(403, 'Anda bukan penilai dokumen ini.');
    }

    /** @return array<string,bool> */
    protected function abilities(SupAppraisal $appraisal, User $actor): array
    {
        $employeeId = $this->employeeIdFor($actor);
        $isSupervisee = $employeeId !== null && (int) $appraisal->supervisee_employee_id === $employeeId;
        $published = $appraisal->status === AppraisalStatus::PUBLISHED;

        return [
            'view_all' => $this->canViewAll($actor),
            'score' => ! $published && ($this->canViewAll($actor) || $actor->can('supervisi.appraisal.calculate')
                || ($employeeId !== null && (int) $appraisal->assessor_employee_id === $employeeId)),
            'calculate' => ! $published && $actor->can('supervisi.appraisal.calculate'),
            'publish' => ! $published && $actor->can('supervisi.appraisal.publish'),
            'reopen' => $published && $actor->can('supervisi.appraisal.publish'),
            'pip' => $actor->can('supervisi.pip.manage'),
            'acknowledge' => $isSupervisee && $published,
        ];
    }

    protected function canViewAll(User $actor): bool
    {
        return $actor->can('supervisi.appraisal.view')
            || $actor->can('supervisi.manage')
            || $actor->can('supervisi.visit.view.all');
    }

    protected function employeeIdFor(User $actor): ?int
    {
        $id = $actor->userable_id ?? null;

        return $actor->userable_type === \App\Models\Employee::class && $id !== null ? (int) $id : null;
    }

    /** @return list<array<string,mixed>> */
    protected function indicatorRows(SupInstrumentVersion $version): array
    {
        return $version->sections->flatMap(fn ($section) => $section->indicators->map(fn ($indicator) => [
            'id' => $indicator->id,
            'section' => $section->title,
            'code' => $indicator->code,
            'statement' => $indicator->statement,
            'guidance' => $indicator->guidance,
            'input_type' => $indicator->input_type->value,
            'is_scored' => (bool) $indicator->is_scored,
            'is_required' => (bool) $indicator->is_required,
            'allow_na' => (bool) $indicator->allow_na,
            'weight' => (float) $indicator->weight,
            'max_score' => $this->engine->indicatorMaxScore($indicator),
            'score_min' => $indicator->score_min,
            'score_max' => $indicator->score_max,
            'options' => $indicator->options->map(fn ($option) => [
                'id' => $option->id,
                'label' => $option->label,
                'score' => (float) $option->score,
                'tone' => $option->tone->value,
            ])->all(),
        ]))->values()->all();
    }

    /** @return list<array<string,mixed>> */
    protected function sourceRows(SupAppraisal $appraisal): array
    {
        return $appraisal->sources->map(fn (SupAppraisalSource $source) => [
            'id' => $source->id,
            'source' => $source->source,
            'rater_employee_id' => $source->rater_employee_id,
            'weight' => (float) $source->weight,
            'score' => $source->score === null ? null : (float) $source->score,
            'comment' => $source->comment,
            'submitted_at' => $source->submitted_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Bobot bawaan penilaian multi-sumber menurut pengaturan sekolah.
     *
     * @return array<string,float>
     */
    public function sourceWeights(): array
    {
        $weights = $this->settings->get('appraisal_source_weights');

        return is_array($weights) ? array_map('floatval', $weights) : [
            'SUPERVISOR' => 60.0, 'PEER' => 20.0, 'SELF' => 10.0, 'STUDENT' => 10.0,
        ];
    }

    /**
     * Matriks kuadran SKP sekolah (kosong = matriks bawaan PermenPANRB 6/2022).
     *
     * @return array<string,array<string,string>>
     */
    protected function matrix(): array
    {
        $matrix = $this->settings->get('skp_quadrant_matrix');

        return is_array($matrix) && $matrix !== [] ? $matrix : AppraisalCalculator::DEFAULT_MATRIX;
    }

    /**
     * Baris ringkas untuk daftar (tanpa memuat seluruh skor indikator).
     *
     * @return array<string,mixed>
     */
    public function presentRow(SupAppraisal $appraisal): array
    {
        $row = $this->present($appraisal);
        $row['scores'] = [];

        return $row;
    }

    /** @return array<string,mixed> */
    protected function present(SupAppraisal $appraisal): array
    {
        return [
            'id' => $appraisal->id,
            'appraisal_type' => $appraisal->appraisal_type->value,
            'period_type' => $appraisal->period_type->value,
            'period_start' => $appraisal->period_start?->toDateString(),
            'period_end' => $appraisal->period_end?->toDateString(),
            'status' => $appraisal->status->value,
            'supervisee' => $appraisal->supervisee === null ? null : [
                'id' => $appraisal->supervisee->id,
                'full_name' => $appraisal->supervisee->full_name,
                'position' => $appraisal->supervisee->position,
            ],
            'assessor' => $appraisal->assessor === null ? null : [
                'id' => $appraisal->assessor->id,
                'full_name' => $appraisal->assessor->full_name,
            ],
            'raw_score' => $appraisal->raw_score,
            'max_score' => $appraisal->max_score,
            'final_score' => $appraisal->final_score,
            'grade_band' => $appraisal->grade_band,
            'grade_label' => $appraisal->grade_label,
            'credit_percent' => $appraisal->credit_percent,
            'attendance_absence_days' => $appraisal->attendance_absence_days,
            'attendance_correction_percent' => $appraisal->attendance_correction_percent,
            'behavior_rating' => $appraisal->behavior_rating,
            'result_rating' => $appraisal->result_rating,
            'final_predicate' => $appraisal->final_predicate,
            'supervision_weight' => $appraisal->supervision_weight,
            'supervision_contribution' => $appraisal->supervision_contribution,
            'snapshot' => $appraisal->snapshot,
            'published_at' => $appraisal->published_at?->toIso8601String(),
            'scores' => $appraisal->relationLoaded('scores')
                ? $appraisal->scores->map(fn (SupAppraisalScore $score) => [
                    'indicator_id' => $score->indicator_id,
                    'code' => $score->indicator?->code,
                    'statement' => $score->indicator?->statement,
                    'score' => $score->score === null ? null : (float) $score->score,
                    'comment' => $score->comment,
                ])->values()->all()
                : [],
        ];
    }
}
