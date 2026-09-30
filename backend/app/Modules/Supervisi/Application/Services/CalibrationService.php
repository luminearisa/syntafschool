<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Models\SupCalibrationScore;
use App\Modules\Supervisi\Models\SupCalibrationSession;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kalibrasi penilai (Fase 4).
 *
 * Dua supervisor yang menilai kasus (atau indikator) yang sama harus memakai
 * ukuran yang sama. Sesi kalibrasi mengumpulkan skor tiap penilai atas
 * indikator yang sama, lalu menghitung rata-rata dan **selisih maks−min**;
 * selisih di atas ambang sesi wajib dibahas sebelum sesi difinalkan.
 *
 * Selain sesi manual, kelas ini menyediakan analisis kesenjangan antar
 * supervisor pada kegiatan nyata (`divergenceFromVisits`) sebagai bahan
 * pembanding saat moderasi.
 */
class CalibrationService
{
    public function __construct(protected AnalyticsService $analytics)
    {
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload, User $actor): SupCalibrationSession
    {
        return SupCalibrationSession::query()->create([
            'title' => $payload['title'],
            'instrument_version_id' => $payload['instrument_version_id'] ?? null,
            'program_id' => $payload['program_id'] ?? null,
            'threshold' => isset($payload['threshold']) ? (float) $payload['threshold'] : 1.0,
            'held_at' => $payload['held_at'] ?? now(),
            'note' => $payload['note'] ?? null,
            'status' => 'DRAFT',
            'created_by' => $actor->id,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function update(SupCalibrationSession $session, array $payload, User $actor): SupCalibrationSession
    {
        $this->assertEditable($session);

        $session->fill([
            'title' => $payload['title'] ?? $session->title,
            'threshold' => isset($payload['threshold']) ? (float) $payload['threshold'] : $session->threshold,
            'held_at' => $payload['held_at'] ?? $session->held_at,
            'note' => $payload['note'] ?? $session->note,
            'instrument_version_id' => $payload['instrument_version_id'] ?? $session->instrument_version_id,
            'program_id' => $payload['program_id'] ?? $session->program_id,
        ])->save();

        return $session->refresh();
    }

    /**
     * Simpan skor penilai: satu baris per (penilai × indikator/kasus).
     *
     * @param  list<array<string, mixed>>  $scores
     */
    public function saveScores(SupCalibrationSession $session, array $scores, User $actor): SupCalibrationSession
    {
        $this->assertEditable($session);

        foreach ($scores as $index => $row) {
            $indicatorId = $row['indicator_id'] ?? null;
            $label = $row['indicator_label'] ?? null;

            if ($indicatorId === null && blank($label)) {
                throw ValidationException::withMessages([
                    "scores.{$index}.indicator_label" => 'Sebutkan indikator (pilih dari instrumen) atau tuliskan nama kasusnya.',
                ]);
            }

            SupCalibrationScore::query()->updateOrCreate(
                [
                    'calibration_session_id' => $session->id,
                    'supervisor_employee_id' => (int) $row['supervisor_employee_id'],
                    'indicator_id' => $indicatorId,
                    'case_code' => $row['case_code'] ?? null,
                ],
                [
                    'indicator_label' => $label,
                    'round' => $row['round'] ?? 'INDIVIDUAL',
                    'score' => $row['score'] ?? null,
                    'comment' => $row['comment'] ?? null,
                ],
            );
        }

        return $session->refresh();
    }

    /**
     * Ringkasan sesi: rata-rata & selisih per indikator, serta daftar indikator
     * yang melewati ambang (wajib dibahas).
     *
     * @return array<string, mixed>
     */
    public function summary(SupCalibrationSession $session): array
    {
        $session->loadMissing('scores.indicator.section', 'program:id,title');

        $groups = $session->scores->groupBy(fn (SupCalibrationScore $score) => $score->indicator_label
            ?? ('indikator-'.$score->indicator_id));

        $rows = $groups->map(function (Collection $scores, string $label) use ($session) {
            $values = $scores->pluck('score')->filter(fn ($value) => $value !== null)->map(fn ($value) => (float) $value);
            $spread = $values->isEmpty() ? null : round($values->max() - $values->min(), 2);
            $average = $values->isEmpty() ? null : round($values->avg(), 2);
            $first = $scores->first();

            return [
                'label' => $first?->indicator_label ?? $first?->indicator?->statement ?? $label,
                'indicator_id' => $first?->indicator_id,
                'section' => $first?->indicator?->section?->title,
                'max_score' => $first?->indicator === null ? null : AnalyticsService::maxScoreOf($first->indicator),
                'scores' => $scores->map(fn (SupCalibrationScore $score) => [
                    'supervisor_employee_id' => $score->supervisor_employee_id,
                    'score' => $score->score,
                    'comment' => $score->comment,
                ])->values()->all(),
                'raters' => $values->count(),
                'average' => $average,
                'spread' => $spread,
                'exceeds_threshold' => $spread !== null && $spread > (float) $session->threshold,
            ];
        })->values();

        $spreads = $rows->pluck('spread')->filter(fn ($value) => $value !== null);

        return [
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'status' => $session->status,
                'held_at' => $session->held_at?->toIso8601String(),
                'threshold' => $session->threshold,
                'note' => $session->note,
                'conclusion' => $session->conclusion,
                'finalized_at' => $session->finalized_at?->toIso8601String(),
                'program' => $session->program?->title,
                'instrument_version_id' => $session->instrument_version_id,
            ],
            'indicators' => $rows->all(),
            'statistics' => [
                'indicators' => $rows->count(),
                'raters' => $session->scores->pluck('supervisor_employee_id')->unique()->count(),
                'average_spread' => $spreads->isEmpty() ? null : round((float) $spreads->avg(), 2),
                'max_spread' => $spreads->isEmpty() ? null : round((float) $spreads->max(), 2),
                'exceeding' => $rows->where('exceeds_threshold', true)->count(),
            ],
            'abilities' => [
                'manage' => true,
                'finalize' => $session->finalized_at === null,
            ],
        ];
    }

    /**
     * Finalisasi sesi: seluruh indikator yang melewati ambang wajib punya
     * kesimpulan, sehingga hasil moderasi tidak sekadar tercatat.
     */
    public function finalize(SupCalibrationSession $session, string $conclusion, User $actor): SupCalibrationSession
    {
        $summary = $this->summary($session);

        if ($summary['indicators'] === []) {
            throw ValidationException::withMessages(['scores' => 'Sesi belum memiliki skor untuk dikalibrasi.']);
        }

        if ($summary['statistics']['exceeding'] > 0 && mb_strlen(trim($conclusion)) < 20) {
            throw ValidationException::withMessages([
                'conclusion' => 'Ada indikator yang melewati ambang selisih; tuliskan kesepakatan penilaian (minimal 20 karakter).',
            ]);
        }

        $session->forceFill([
            'conclusion' => $conclusion,
            'status' => 'FINALIZED',
            'finalized_at' => now(),
            'finalized_by' => $actor->id,
        ])->save();

        return $session->refresh();
    }

    /** Daftar sesi beserta statistik ringkasnya. */
    public function paginate(array $filters = [], int $perPage = 25)
    {
        return SupCalibrationSession::query()
            ->with('program:id,title')
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['program_id']), fn ($query) => $query->where('program_id', (int) $filters['program_id']))
            ->orderByDesc('held_at')
            ->paginate($perPage);
    }

    /**
     * Bahan pembanding dari kegiatan nyata: rata-rata skor tiap supervisor untuk
     * indikator yang sama pada instrumen yang sama.
     *
     * @return list<array<string, mixed>>
     */
    public function divergenceFromVisits(?int $instrumentVersionId = null, int $limit = 15): array
    {
        $rows = DB::table('sup_visit_answers')
            ->join('sup_visits', 'sup_visits.id', '=', 'sup_visit_answers.visit_id')
            ->join('sup_indicators', 'sup_indicators.id', '=', 'sup_visit_answers.indicator_id')
            ->whereNotNull('sup_visits.finalized_at')
            ->where('sup_visit_answers.is_na', false)
            ->whereNotNull('sup_visit_answers.score')
            ->when($instrumentVersionId !== null, fn ($query) => $query->where('sup_visits.instrument_version_id', $instrumentVersionId))
            ->groupBy('sup_visits.supervisor_employee_id', 'sup_indicators.id', 'sup_indicators.statement')
            ->select(
                'sup_visits.supervisor_employee_id',
                'sup_indicators.id as indicator_id',
                'sup_indicators.statement',
                DB::raw('avg(sup_visit_answers.score) as rata'),
                DB::raw('count(*) as jawaban'),
            )
            ->get();

        $names = DB::table('employees')
            ->whereIn('id', $rows->pluck('supervisor_employee_id')->unique()->all())
            ->pluck('full_name', 'id');

        return $rows->groupBy('indicator_id')
            ->map(function (Collection $group) use ($names) {
                $values = $group->pluck('rata')->map(fn ($value) => (float) $value);

                return [
                    'indicator_id' => (int) $group->first()->indicator_id,
                    'statement' => $group->first()->statement,
                    'supervisors' => $group->map(fn ($row) => [
                        'supervisor_employee_id' => (int) $row->supervisor_employee_id,
                        'full_name' => $names[$row->supervisor_employee_id] ?? null,
                        'average' => round((float) $row->rata, 2),
                        'answers' => (int) $row->jawaban,
                    ])->values()->all(),
                    'spread' => round($values->max() - $values->min(), 2),
                    'school_average' => round((float) $values->avg(), 2),
                ];
            })
            ->filter(fn (array $row) => $row['spread'] > 0)
            ->sortByDesc('spread')
            ->take($limit)
            ->values()
            ->all();
    }

    protected function assertEditable(SupCalibrationSession $session): void
    {
        if ($session->finalized_at !== null) {
            throw ValidationException::withMessages([
                'session' => 'Sesi sudah difinalkan; buka kembali tidak diizinkan. Buat sesi kalibrasi baru untuk periode berikutnya.',
            ]);
        }
    }

    /** Jumlah sesi yang belum difinalkan (untuk pengingat & dasbor). */
    public function pendingCount(): int
    {
        return SupCalibrationSession::query()->whereNull('finalized_at')->count();
    }

    /** Rata-rata selisih skor antara dua supervisor pada instrumen yang sama. */
    public function spreadForVisit(SupVisit $visit): ?float
    {
        if ($visit->instrument_version_id === null) {
            return null;
        }

        $peers = SupVisit::query()
            ->where('instrument_version_id', $visit->instrument_version_id)
            ->where('supervisee_employee_id', $visit->supervisee_employee_id)
            ->whereNotNull('final_score')
            ->where('id', '!=', $visit->id)
            ->pluck('final_score')
            ->map(fn ($value) => (float) $value);

        if ($peers->isEmpty() || $visit->final_score === null) {
            return null;
        }

        $values = $peers->push((float) $visit->final_score);

        return round($values->max() - $values->min(), 2);
    }
}
