<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\InstrumentVersionStatus;
use App\Modules\Supervisi\Domain\Enums\VisitStatus;
use App\Modules\Supervisi\Models\SupAcknowledgment;
use App\Modules\Supervisi\Models\SupInstrumentVersion;
use App\Modules\Supervisi\Models\SupVisit;
use App\Modules\Supervisi\Models\SupVisitRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finalisasi hasil supervisi.
 *
 * Finalisasi adalah momen "hasil dikunci":
 *  - diperiksa dulu kelengkapan wajib (indikator wajib, wawancara
 *    pasca-observasi, temuan/rekomendasi bila nilai rendah);
 *  - nilai akhir dihitung server, bukan dari klien;
 *  - dibuat **snapshot** instrumen + jawaban + hitungan pada saat itu, sehingga
 *    revisi instrumen di masa depan tidak dapat mengubah dokumen lama;
 *  - dibuat baris tanda tangan (menunggu) untuk yang disupervisi, dasar hak
 *    menandai hasil maupun mengajukan keberatan;
 *  - seluruh perubahan dicatat pada riwayat revisi dan jejak audit.
 */
class VisitFinalizationService
{
    /** Kegiatan yang mensyaratkan wawancara pasca-observasi. */
    public const POST_CONFERENCE_KINDS = ['CLASS_OBSERVATION', 'CLINICAL', 'PEER', 'LESSON_STUDY'];

    public function __construct(
        private readonly ScoringEngine $engine,
        private readonly VisitAnswerService $answers,
        private readonly VisitAccessService $access,
        private readonly AuditLogger $audit,
        private readonly SupervisiSettings $settings,
    ) {}

    /**
     * Daftar syarat yang belum terpenuhi. Kosong berarti siap difinalkan.
     *
     * @return list<string>
     */
    public function checklist(SupVisit $visit): array
    {
        $visit->loadMissing([
            'instrumentVersion.gradeScale',
            'instrumentVersion.sections.indicators.options',
            'conferences',
            'findings',
            'answers',
        ]);

        $version = $visit->instrumentVersion;

        if ($version === null) {
            return ['Kegiatan belum memiliki instrumen.'];
        }

        $problems = [];

        if ($version->status !== InstrumentVersionStatus::PUBLISHED) {
            $problems[] = 'Versi instrumen kegiatan ini tidak lagi berstatus terbit.';
        }

        $preview = $this->answers->preview($visit, $version);
        $summary = $preview['summary'] ?? [];

        if (($summary['scored_total'] ?? 0) === 0) {
            $problems[] = 'Belum ada indikator berskor yang tersedia pada instrumen ini.';
        }

        $missing = $summary['missing_required'] ?? [];
        if ($missing !== []) {
            $problems[] = 'Masih ada '.count($missing).' indikator wajib yang belum diisi.';
        }

        if ($this->requiresPostConference($visit) && $visit->conferences->firstWhere('kind', 'POST') === null) {
            $problems[] = 'Wawancara pasca-observasi belum dicatat.';
        }

        $hasFinding = $visit->findings->isNotEmpty();
        $final = $summary['final'] ?? null;
        $threshold = (float) $this->settings->get('finding_required_below_percent', 80);

        if ($final !== null && $final < $threshold && ! $hasFinding) {
            $problems[] = "Nilai akhir {$final} berada di bawah {$threshold}: wajib diisi minimal satu temuan beserta rekomendasi.";
        }

        $criticalMinimum = $this->criticalIndicatorsAtMinimum($visit, $version, $preview);

        if ($criticalMinimum !== [] && ! $hasFinding) {
            $problems[] = 'Indikator kritis bernilai minimum ('.count($criticalMinimum).' butir): wajib diberi temuan dan rekomendasi.';
        }

        if ($this->settings->bool('require_action_plan_before_finalize')
            && $version->requires_action_plan
            && $visit->actionPlans()->count() === 0) {
            $problems[] = 'Instrumen ini mewajibkan Rencana Tindak Lanjut sebelum finalisasi.';
        }

        return $problems;
    }

    /**
     * Kunci hasil kegiatan.
     *
     * @param  array<string,mixed>  $data
     */
    public function finalize(SupVisit $visit, User $actor, array $data = []): SupVisit
    {
        $visit->loadMissing(['instrumentVersion.gradeScale', 'conferences', 'findings', 'answers']);

        if ($visit->finalized_at !== null) {
            $this->fail('visit', 'Kegiatan ini sudah difinalkan.');
        }

        if ($visit->status === VisitStatus::CANCELLED) {
            $this->fail('visit', 'Kegiatan ini sudah dibatalkan.');
        }

        $problems = $this->checklist($visit);

        if ($problems !== []) {
            throw ValidationException::withMessages(['checklist' => $problems]);
        }

        $version = $visit->instrumentVersion;
        $preview = $this->answers->preview($visit, $version);
        $summary = $preview['summary'] ?? [];
        $band = $preview['band'];
        $employeeId = $this->access->employeeIdFor($actor);

        $snapshot = $this->snapshot($visit, $version, $summary, $band, $actor, $preview['indicator_scores'] ?? []);

        DB::transaction(function () use ($visit, $actor, $employeeId, $data, $snapshot, $summary, $band): void {
            // Nilai per bagian + kolom total pada kegiatan.
            $this->answers->syncTotals($visit, $actor);

            $before = [
                'status' => $visit->status->value,
                'final_score' => $visit->final_score,
                'finalized_at' => $visit->finalized_at?->toIso8601String(),
                'revision' => $visit->revision,
            ];

            $visit->forceFill([
                'status' => VisitStatus::COMPLETED->value,
                'finalized_at' => now(),
                'finalized_by' => $actor->id,
                'revision' => max(1, (int) $visit->revision + 1),
                'summary' => $data['summary'] ?? $visit->summary,
                'strengths' => $data['strengths'] ?? $visit->strengths,
                'improvements' => $data['improvements'] ?? $visit->improvements,
                'snapshot' => $snapshot,
            ])->save();

            // Baris tanda tangan menunggu untuk yang disupervisi.
            SupAcknowledgment::query()->updateOrCreate(
                ['visit_id' => $visit->id, 'employee_id' => $visit->supervisee_employee_id],
                ['acknowledged_at' => null, 'signature_hash' => null, 'note' => null],
            );

            SupVisitRevision::query()->create([
                'visit_id' => $visit->id,
                'revision' => $visit->revision,
                'action' => 'finalized',
                'before' => $before,
                'after' => [
                    'status' => $visit->status->value,
                    'final_score' => $visit->final_score,
                    'grade_band' => $visit->grade_band,
                    'finalized_at' => $visit->finalized_at?->toIso8601String(),
                    'snapshot_checksum' => $snapshot['checksum'] ?? null,
                ],
                'reason' => null,
                'actor_id' => $actor->id,
            ]);

            // Jadwal yang melahirkan kegiatan ini ditandai selesai.
            $schedule = $visit->schedule;
            if ($schedule !== null && $schedule->status->value !== 'CANCELLED') {
                $schedule->forceFill(['status' => 'DONE'])->save();
            }

            $this->audit->log('visit.finalized', $visit, $before, [
                'final_score' => $summary['final'] ?? null,
                'band' => $band['code'] ?? $band['label'] ?? null,
                'finalized_by_employee_id' => $employeeId,
            ], null, $actor);
        });

        return $visit->refresh();
    }

    /**
     * Buka kembali hasil yang sudah difinalkan (koreksi) tanpa menghapus jejak.
     */
    public function reopen(SupVisit $visit, User $actor, string $reason): SupVisit
    {
        if ($visit->finalized_at === null) {
            $this->fail('visit', 'Kegiatan ini belum difinalkan.');
        }

        DB::transaction(function () use ($visit, $actor, $reason): void {
            $before = [
                'status' => $visit->status->value,
                'final_score' => $visit->final_score,
                'finalized_at' => $visit->finalized_at?->toIso8601String(),
            ];

            $visit->forceFill([
                'status' => VisitStatus::ANALYZED->value,
                'finalized_at' => null,
                'finalized_by' => null,
                'revision' => (int) $visit->revision + 1,
                'is_disputed' => false,
                'snapshot' => [
                    ...(array) $visit->snapshot,
                    'reopened' => [
                        'at' => now()->toIso8601String(),
                        'by' => $actor->name,
                        'reason' => $reason,
                        'previous_final_score' => $visit->final_score,
                    ],
                ],
            ])->save();

            SupVisitRevision::query()->create([
                'visit_id' => $visit->id,
                'revision' => $visit->revision,
                'action' => 'reopened',
                'before' => $before,
                'after' => ['status' => VisitStatus::ANALYZED->value, 'finalized_at' => null],
                'reason' => $reason,
                'actor_id' => $actor->id,
            ]);

            $this->audit->log('visit.reopened', $visit, $before, ['status' => VisitStatus::ANALYZED->value], $reason, $actor);
        });

        return $visit->refresh();
    }

    /** Kegiatan ini mensyaratkan wawancara pasca-observasi? */
    public function requiresPostConference(SupVisit $visit): bool
    {
        if (! $this->settings->bool('require_post_conference')) {
            return false;
        }

        return in_array($visit->activity_kind->value, self::POST_CONFERENCE_KINDS, true);
    }

    /**
     * Indikator kritis yang nilainya menyentuh batas minimum.
     *
     * @param  array<string,mixed>  $preview
     * @return list<int>
     */
    protected function criticalIndicatorsAtMinimum(SupVisit $visit, SupInstrumentVersion $version, array $preview): array
    {
        $scores = collect($preview['indicator_scores'] ?? [])->keyBy('indicator_id');
        $result = [];

        foreach ($version->indicators as $indicator) {
            $row = $scores->get($indicator->id);

            if ($row === null || $row['computed_score'] === null || ! $indicator->is_critical) {
                continue;
            }

            if ($row['computed_score'] <= $indicator->minimumScore()) {
                $result[] = (int) $indicator->id;
            }
        }

        return $result;
    }

    /**
     * Snapshot beku: jawaban, definisi indikator, skala predikat, dan hitungan.
     *
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>|null  $band
     * @param  list<array<string,mixed>>  $indicatorScores
     * @return array<string,mixed>
     */
    protected function snapshot(
        SupVisit $visit,
        ?SupInstrumentVersion $version,
        array $summary,
        ?array $band,
        User $actor,
        array $indicatorScores,
    ): array {
        $visit->loadMissing(['answers']);

        $sections = [];
        if ($version !== null) {
            $version->loadMissing('sections.indicators.options');

            foreach ($version->sections as $section) {
                $sections[] = [
                    'section_id' => (int) $section->id,
                    'title' => $section->title,
                    'weight' => (float) $section->weight,
                    'indicators' => $section->indicators->map(fn ($indicator) => [
                        'indicator_id' => (int) $indicator->id,
                        'code' => $indicator->code,
                        'statement' => $indicator->statement,
                        'input_type' => $indicator->input_type->value,
                        'weight' => (float) $indicator->weight,
                        'max_score' => $this->engine->indicatorMaxScore($indicator),
                        'is_critical' => (bool) $indicator->is_critical,
                        'min_comment_below' => $indicator->min_comment_below === null ? null : (float) $indicator->min_comment_below,
                        'options' => $indicator->options->map(fn ($option) => [
                            'label' => $option->label,
                            'score' => (float) $option->score,
                        ])->all(),
                    ])->all(),
                ];
            }
        }

        $answers = $visit->answers->map(fn ($answer) => [
            'indicator_id' => (int) $answer->indicator_id,
            'option_id' => $answer->option_id,
            'score' => $answer->score,
            'value_number' => $answer->value_number,
            'value_text' => $answer->value_text,
            'value_long_text' => $answer->value_long_text,
            'value_date' => $answer->value_date?->toDateString(),
            'value_json' => $answer->value_json,
            'is_na' => (bool) $answer->is_na,
            'comment' => $answer->comment,
        ])->values()->all();

        $payload = [
            'instrument' => $version?->instrument === null ? null : [
                'id' => (int) $version->instrument->id,
                'code' => $version->instrument->code,
                'name' => $version->instrument->name,
                'category' => $version->instrument->category->value,
            ],
            'version' => $version === null ? null : [
                'id' => (int) $version->id,
                'version' => (int) $version->version,
                'scoring_mode' => $version->scoring_mode->value,
                'checksum' => $version->checksum,
                'requires_action_plan' => (bool) $version->requires_action_plan,
            ],
            'grade_scale' => $version?->gradeScale === null ? null : [
                'code' => $version->gradeScale->code,
                'scale_type' => $version->gradeScale->scale_type,
                'bands' => $version->gradeScale->bands,
            ],
            'sections' => $sections,
            'answers' => $answers,
            'computed' => [
                'raw' => $summary['raw'] ?? null,
                'max' => $summary['max'] ?? null,
                'final' => $summary['final'] ?? null,
                'band' => $band['code'] ?? $band['label'] ?? null,
                'band_label' => $band['label'] ?? null,
                'sections' => $summary['sections'] ?? [],
                'indicator_scores' => $indicatorScores,
            ],
            'frozen_at' => now()->toIso8601String(),
            'frozen_by' => [
                'user_id' => $actor->id,
                'name' => $actor->name,
                'employee_id' => $this->access->employeeIdFor($actor),
            ],
        ];

        $payload['checksum'] = hash('sha256', json_encode([
            $payload['version'],
            $payload['answers'],
            $payload['computed']['final'],
        ], JSON_UNESCAPED_UNICODE));

        return $payload;
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
