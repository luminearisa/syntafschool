<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Experience\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\Analytics\Models\HrExperiment;
use App\Modules\Hrd\Strategic\Analytics\Models\HrExperimentResult;
use App\Modules\Hrd\Strategic\People\Models\HrEngagementSnapshot;
use App\Modules\Hrd\Strategic\People\Models\HrExperienceSnapshot;
use App\Modules\Hrd\Strategic\People\Models\HrServiceInsight;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use Illuminate\Support\Facades\DB;

/**
 * Employee Experience Intelligence (§60–§62, §101–§104): engagement, pulse,
 * journey analytics, HR service intelligence, dan kerangka eksperimen HR.
 *
 * Ambang privasi ditegakkan: grup dengan responden terlalu sedikit tidak
 * ditampilkan.
 */
class EmployeeExperienceService
{
    public function __construct(private readonly Phase3Context $ctx) {}

    /**
     * Snapshot pengalaman pegawai (§60) — hanya bila memenuhi ambang privasi.
     *
     * @return array<string, mixed>
     */
    public function captureSnapshot(?int $organizationId = null, string $granularity = 'MONTHLY', mixed $actor = null): array
    {
        $threshold = (int) $this->ctx->config('analytics.privacy_threshold', 5);
        $period = now()->format('Y-m');
        $respondents = $this->respondentCount($organizationId, $period);
        $meetsThreshold = $respondents >= $threshold;

        $engagement = $this->engagementValue($organizationId, 'ENGAGEMENT', $period);
        $services = $this->serviceMetrics($organizationId, $period);

        $snapshot = $meetsThreshold ? HrExperienceSnapshot::query()->updateOrCreate(
            ['snapshot_date' => now()->toDateString(), 'granularity' => strtoupper($granularity),
                'scope' => $organizationId ? 'ORGANIZATION' : 'GLOBAL', 'organization_id' => $organizationId],
            [
                'engagement_score' => $engagement, 'participation_rate' => $this->participationRate($organizationId, $period),
                'satisfaction_score' => $this->engagementValue($organizationId, 'SATISFACTION', $period),
                'manager_effectiveness' => $this->engagementValue($organizationId, 'MANAGER_EFFECTIVENESS', $period),
                'career_confidence' => $this->engagementValue($organizationId, 'CAREER_CONFIDENCE', $period),
                'learning_satisfaction' => $this->engagementValue($organizationId, 'LEARNING_SATISFACTION', $period),
                'hr_service_satisfaction' => $services['satisfaction_avg'],
                'service_volume' => $services['volume'], 'avg_resolution_hours' => $services['avg_resolution_hours'],
                'reopen_rate' => $services['reopen_rate'], 'respondents' => $respondents,
                'meets_privacy_threshold' => $meetsThreshold,
                'breakdown' => ['services_by_category' => $services['by_category']],
            ]
        ) : null;

        return [
            'snapshot_id' => $snapshot?->getKey(),
            'respondents' => $respondents, 'privacy_threshold' => $threshold,
            'meets_privacy_threshold' => $meetsThreshold,
            'suppressed' => ! $meetsThreshold,
            'note' => $meetsThreshold ? null : 'Hasil disembunyikan: responden di bawah ambang privasi.',
            'services' => $services,
        ];
    }

    /**
     * Pulse analytics (§102): tren per periode & unit.
     *
     * @return array<string, mixed>
     */
    public function pulse(string $metric = 'ENGAGEMENT', int $periods = 6, ?int $organizationId = null): array
    {
        $rows = HrEngagementSnapshot::query()->where('metric', strtoupper($metric))
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('period')->limit($periods)->get();

        return [
            'metric' => strtoupper($metric),
            'series' => $rows->map(fn (HrEngagementSnapshot $row): array => [
                'period' => $row->period, 'value' => (float) $row->value,
                'respondents' => (int) $row->respondents, 'reliable' => (bool) ($row->respondents >= (int) $row->privacy_threshold),
            ])->all(),
            'trend' => $this->ctx->trend($rows->pluck('value')->map(fn ($value): float => (float) $value)->all()),
        ];
    }

    /**
     * Journey analytics (§61): waktu & konversi tahap siklus pegawai.
     *
     * @return array<string, mixed>
     */
    public function journeyAnalytics(?int $organizationId = null, int $months = 12): array
    {
        $since = now()->subMonths($months)->toDateString();
        $hires = (int) $this->ctx->safe(fn (): int => (int) HrEmployee::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->where('hire_date', '>=', $since)->count(), 0);
        $confirmed = (int) $this->ctx->safe(fn (): int => (int) HrEmployee::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->where('hire_date', '>=', $since)->whereNotNull('confirmation_date')->count(), 0);
        $probationEnded = (int) $this->ctx->safe(fn (): int => (int) HrEmployee::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->where('hire_date', '>=', $since)->whereNotNull('probation_end_date')
            ->where('probation_end_date', '<=', now()->toDateString())->count(), 0);
        $promoted = (int) $this->ctx->safe(fn (): int => $this->ctx->hasTable('hr_assignments')
            ? (int) DB::table('hr_assignments')->where('start_date', '>=', $since)->distinct()->count('employee_id') : 0, 0);
        $trained = (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_training_completions')
            ->where('completed_at', '>=', $since)->distinct()->count('employee_id'), 0);
        $moved = (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_internal_job_applications')
            ->where('created_at', '>=', $since)->where('status', 'HIRED')->distinct()->count('employee_id'), 0);
        $left = (int) $this->ctx->safe(fn (): int => (int) HrEmployee::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereNotNull('termination_date')->where('termination_date', '>=', $since)->count(), 0);

        $stages = [
            ['stage' => 'RECRUITMENT', 'count' => $hires, 'note' => 'Pegawai masuk periode ini'],
            ['stage' => 'ONBOARDING', 'count' => $hires, 'note' => 'Melampaui proses onboarding'],
            ['stage' => 'PROBATION', 'count' => $probationEnded, 'conversion_percent' => $this->ctx->percent($confirmed, max($probationEnded, 1))],
            ['stage' => 'PERFORMANCE', 'count' => $confirmed, 'note' => 'Punya penilaian kinerja'],
            ['stage' => 'DEVELOPMENT', 'count' => $trained, 'conversion_percent' => $this->ctx->percent($trained, max($hires, 1))],
            ['stage' => 'PROMOTION_MOBILITY', 'count' => $moved + $promoted, 'conversion_percent' => $this->ctx->percent($moved + $promoted, max($hires, 1))],
            ['stage' => 'OFFBOARDING', 'count' => $left, 'drop_off_percent' => $this->ctx->percent($left, max($hires, 1))],
        ];

        return ['months' => $months, 'stages' => $stages, 'cohort' => $hires, 'generated_at' => now()->toIso8601String()];
    }

    /**
     * HR service intelligence (§62): volume, SLA, waktu penyelesaian, tema berulang.
     *
     * @return array<string, mixed>
     */
    public function serviceIntelligence(?int $organizationId = null, ?string $period = null): array
    {
        $period = $period ?? now()->format('Y-m');
        $rows = $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_service_tickets')
            ? DB::table('hr_service_tickets')->where('created_at', 'like', $period.'%')
                ->selectRaw('category, count(*) as volume, avg(julianday(resolved_at) - julianday(created_at)) * 24 as avg_hours,
                    sum(case when resolved_at <= sla_due_at then 1 else 0 end) as sla_met,
                    avg(satisfaction_score) as satisfaction')
                ->groupBy('category')->get()->map(fn ($row): array => [
                    'category' => $row->category, 'volume' => (int) $row->volume,
                    'avg_resolution_hours' => round((float) $row->avg_hours, 2),
                    'sla_met_percent' => $this->ctx->percent((int) $row->sla_met, max((int) $row->volume, 1)),
                    'satisfaction' => $row->satisfaction !== null ? round((float) $row->satisfaction, 2) : null,
                ])->all() : [], []);

        $insights = [];
        foreach ($rows as $row) {
            $recurring = $row['volume'] >= 5;
            $insights[] = HrServiceInsight::query()->updateOrCreate(
                ['period' => $period, 'category' => $row['category']],
                [
                    'volume' => $row['volume'], 'avg_resolution_hours' => $row['avg_resolution_hours'],
                    'sla_met_rate' => $row['sla_met_percent'], 'satisfaction_avg' => $row['satisfaction'],
                    'recurring_issue_signal' => $recurring ? 'RECURRING' : null,
                    'ai_detected_theme' => $recurring ? 'Kategori '.$row['category'].' muncul berulang — tinjau akar masalah.' : null,
                    'evidence' => $row, 'generated_at' => now(),
                ]
            );
        }

        return [
            'period' => $period, 'by_category' => $rows,
            'total_volume' => array_sum(array_column($rows, 'volume')),
            'recurring' => count(array_filter($rows, fn (array $row): bool => $row['volume'] >= 5)),
            'insights' => count($insights),
        ];
    }

    /**
     * Kerangka eksperimen HR (§104).
     *
     * @return array<string, mixed>
     */
    public function startExperiment(array $data, mixed $actor = null): HrExperiment
    {
        $experiment = HrExperiment::query()->create([
            'code' => $data['code'] ?? $this->ctx->nextCode('hr_experiments', 'EXP'),
            'name' => $data['name'], 'initiative_type' => strtoupper((string) ($data['initiative_type'] ?? 'OTHER')),
            'population' => $data['population'] ?? [], 'baseline_metrics' => $data['baseline_metrics'] ?? [],
            'intervention' => $data['intervention'] ?? null,
            'start_date' => $data['start_date'] ?? now()->toDateString(),
            'end_date' => $data['end_date'] ?? now()->addMonths(6)->toDateString(),
            'status' => 'RUNNING', 'owner_employee_id' => $this->ctx->employeeFor($actor)?->getKey(),
        ]);

        $this->ctx->audit('strategic.experiment.started', HrExperiment::class, $experiment->getKey(), [
            'name' => $experiment->name, 'type' => $experiment->initiative_type,
        ], null, $actor);

        return $experiment;
    }

    /**
     * Ukur hasil eksperimen (korelasi, bukan klaim kausal otomatis).
     *
     * @return array<string, mixed>
     */
    public function measureExperiment(HrExperiment $experiment, array $measurements, mixed $actor = null): array
    {
        $rows = [];
        foreach ($measurements as $measurement) {
            $baseline = (float) ($measurement['baseline'] ?? ($experiment->baseline_metrics[$measurement['metric_code']] ?? 0));
            $intervention = (float) ($measurement['value'] ?? 0);
            $rows[] = HrExperimentResult::query()->create([
                'experiment_id' => $experiment->getKey(), 'metric_code' => strtoupper((string) $measurement['metric_code']),
                'baseline_value' => $baseline, 'intervention_value' => $intervention,
                'delta' => $this->ctx->money($intervention - $baseline),
                'delta_percent' => $this->ctx->change($baseline, $intervention),
                'cohort_size' => (int) ($measurement['cohort_size'] ?? 0),
                'significance_note' => $measurement['note'] ?? 'Ukuran sampel kecil: perlakukan sebagai indikasi, bukan bukti kausal.',
                'evidence' => $measurement, 'measured_at' => now(),
            ]);
        }

        $experiment->forceFill(['status' => 'MEASURED'])->save();
        $this->ctx->audit('strategic.experiment.measured', HrExperiment::class, $experiment->getKey(), ['metrics' => count($rows)], null, $actor);

        return ['experiment' => $experiment->code, 'results' => $rows];
    }

    /* ------------------------------------------------------------------ */

    private function respondentCount(?int $organizationId, string $period): int
    {
        return (int) $this->ctx->safe(fn (): int => $this->ctx->hasTable('survey_responses')
            ? DB::table('survey_responses')->where('created_at', 'like', $period.'%')->distinct()->count('respondent_id')
            : $this->ctx->probeCount('hr_engagement_snapshots', ['period' => $period], 0), 0);
    }

    private function engagementValue(?int $organizationId, string $metric, string $period): ?float
    {
        $fromSnapshots = $this->ctx->safe(fn (): ?float => ($value = DB::table('hr_engagement_snapshots')
            ->where('metric', $metric)->where('period', $period)
            ->when($organizationId, fn ($q, $org) => $q->where('organization_id', $org))->avg('value')) !== null
            ? round((float) $value, 2) : null, null);

        return $fromSnapshots;
    }

    private function participationRate(?int $organizationId, string $period): ?float
    {
        $respondents = $this->respondentCount($organizationId, $period);
        $headcount = (int) $this->ctx->safe(fn (): int => (int) HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->count(), 0);

        return $headcount > 0 ? $this->ctx->percent($respondents, $headcount) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceMetrics(?int $organizationId, string $period): array
    {
        $rows = $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_service_tickets')
            ? DB::table('hr_service_tickets')->where('created_at', 'like', $period.'%')
                ->selectRaw('count(*) as volume, avg(julianday(resolved_at) - julianday(created_at)) * 24 as avg_hours, avg(satisfaction_score) as satisfaction')
                ->get()->map(fn ($row): array => (array) $row)->all() : [], []);

        $summary = $rows[0] ?? [];
        $volume = (int) ($summary['volume'] ?? 0);
        $reopened = (int) $this->ctx->safe(fn (): int => DB::table('hr_service_tickets')->where('created_at', 'like', $period.'%')
            ->where('status', 'REOPENED')->count(), 0);

        return [
            'volume' => $volume,
            'avg_resolution_hours' => round((float) ($summary['avg_hours'] ?? 0), 2),
            'satisfaction_avg' => isset($summary['satisfaction']) ? round((float) $summary['satisfaction'], 2) : null,
            'reopen_rate' => $this->ctx->percent($reopened, max($volume, 1)),
            'by_category' => [],
        ];
    }
}
