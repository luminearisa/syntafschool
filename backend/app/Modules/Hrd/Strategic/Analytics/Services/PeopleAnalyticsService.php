<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\Analytics\Models\HrAnalyticsDashboard;
use App\Modules\Hrd\Strategic\Analytics\Models\HrMetricSnapshot;
use App\Modules\Hrd\Strategic\Analytics\Models\HrPeopleAnalyticsSnapshot;
use App\Modules\Hrd\Strategic\Analytics\Models\HrWorkforceSnapshot;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use Illuminate\Support\Facades\DB;

/**
 * People Analytics (§43, §45, §47, §48, §150): dashboard lintas domain dengan
 * drill-down, filter, perbandingan periode, dan penghormatan scope.
 *
 * Analitik membaca read-model/snapshot — bukan query berat ke tabel transaksi.
 */
class PeopleAnalyticsService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly MetricEngine $metrics,
    ) {}

    /**
     * Dashboard siap saji: metrik + dimensi + tren, sudah dibatasi scope.
     *
     * @return array<string, mixed>
     */
    public function dashboard(string $code, array $filters = [], mixed $user = null): array
    {
        $dashboard = HrAnalyticsDashboard::query()->where('code', strtoupper($code))->with('widgets')->first();
        $metricCodes = $dashboard
            ? $dashboard->widgets->pluck('metric_code')->filter()->unique()->all()
            : ['HEADCOUNT', 'TURNOVER_RATE', 'ABSENTEEISM_RATE', 'COST_PER_EMPLOYEE'];

        $metrics = [];
        foreach ($metricCodes as $metricCode) {
            $metrics[$metricCode] = $this->metrics->evaluate((string) $metricCode, $filters, null, $user);
        }

        return [
            'dashboard' => $dashboard ? ['code' => $dashboard->code, 'name' => $dashboard->name, 'audience' => $dashboard->audience] : null,
            'scope' => $this->ctx->scopeOf($user, true)['type'],
            'filters' => $filters,
            'metrics' => $metrics,
            'drill_down' => $this->drillDown($filters, $user),
            'note' => 'Angka mengikuti definisi metrik kanonik dan scope pengguna.',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function drillDown(array $filters = [], mixed $user = null): array
    {
        $scope = $this->ctx->scopeOf($user, true);
        $base = HrEmployee::query()->where('is_active', true);

        // Row-level security: scope pemanggil membatasi baris yang terlihat.
        if ($user !== null) {
            match ($scope['type']) {
                'SELF' => $base->whereKey($scope['employee_id'] ?? 0),
                'DEPARTMENT' => $base->where('department_id', $scope['department_id'] ?? 0),
                'ORGANIZATION' => $base->where('organization_id', $scope['organization_id'] ?? 0),
                default => null,
            };
        }
        $base->when($filters['organization_id'] ?? null, fn ($q, $org) => $q->where('organization_id', $org))
            ->when($filters['department_id'] ?? null, fn ($q, $dept) => $q->where('department_id', $dept));

        return [
            'by_department' => (clone $base)->selectRaw('department_id, count(*) as total')->groupBy('department_id')->get()
                ->map(fn ($row): array => ['department_id' => $row->department_id, 'headcount' => (int) $row->total])->all(),
            'by_worker_type' => (clone $base)->selectRaw('worker_type, count(*) as total')->groupBy('worker_type')->get()
                ->map(fn ($row): array => ['worker_type' => $row->worker_type, 'headcount' => (int) $row->total])->all(),
            'by_position' => (clone $base)->selectRaw('position_id, count(*) as total')->groupBy('position_id')->get()
                ->map(fn ($row): array => ['position_id' => $row->position_id, 'headcount' => (int) $row->total])->all(),
            'dimensions_available' => ['ORGANIZATION', 'DEPARTMENT', 'POSITION', 'LOCATION', 'WORKER_TYPE', 'EMPLOYMENT_TYPE', 'GRADE', 'TENURE_BAND'],
        ];
    }

    /**
     * Snapshot workforce (§47) — fondasi analitik historis.
     *
     * @return array<string, mixed>
     */
    public function captureWorkforceSnapshot(string $granularity = 'MONTHLY', ?int $organizationId = null, mixed $actor = null): HrWorkforceSnapshot
    {
        $date = now()->toDateString();
        $base = HrEmployee::query()->where('is_active', true)->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId));
        $headcount = (int) $base->clone()->count();
        $payrollCost = (float) $this->ctx->safe(fn (): float => $this->ctx->hasTable('hr_payroll_items')
            ? (float) DB::table('hr_payroll_items')->whereIn('employee_id', $base->clone()->select('id'))->where('created_at', '>=', now()->startOfMonth())->sum('net_amount') : 0.0, 0.0);
        $absence = (float) $this->ctx->safe(fn (): float => $this->ctx->hasTable('hr_attendance_records')
            ? (float) DB::table('hr_attendance_records')->whereIn('employee_id', $base->clone()->select('id'))
                ->where('date', 'like', now()->format('Y-m').'%')->whereIn('status', ['ABSENT', 'UNPAID', 'NO_SHOW'])->count() : 0.0, 0.0);
        $skillCoverage = (float) $this->ctx->safe(fn (): float => $this->ctx->hasTable('hr_employee_skills')
            ? $this->ctx->percent((int) DB::table('hr_employee_skills')->whereIn('employee_id', $base->clone()->select('id'))->distinct()->count('employee_id'), max($headcount, 1)) : 0.0, 0.0);

        return HrWorkforceSnapshot::query()->updateOrCreate(
            ['snapshot_date' => $date, 'granularity' => strtoupper($granularity), 'scope' => $organizationId ? 'ORGANIZATION' : 'GLOBAL', 'organization_id' => $organizationId],
            [
                'headcount' => $headcount, 'active_headcount' => $headcount,
                'open_positions' => (int) $this->ctx->probeCount('hr_job_openings', ['status' => 'OPEN'], 0),
                'positions' => (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_positions')->when($organizationId, fn ($q, $org) => $q->where('organization_id', $org))->count(), 0),
                'avg_tenure_months' => $this->ctx->score((float) $this->ctx->safe(fn (): float => (float) $base->clone()->avg(DB::raw('julianday("now") - julianday(hire_date)')) / 30.44, 0.0)),
                'payroll_cost' => $this->ctx->money($payrollCost), 'absence_days' => $absence,
                'absence_rate' => $this->ctx->percent($absence, max($headcount * 22, 1)),
                'skill_coverage' => $skillCoverage,
                'breakdown' => [
                    'by_department' => $base->clone()->selectRaw('department_id, count(*) as total')->groupBy('department_id')->pluck('total', 'department_id')->all(),
                    'by_worker_type' => $base->clone()->selectRaw('worker_type, count(*) as total')->groupBy('worker_type')->pluck('total', 'worker_type')->all(),
                ],
            ]
        );
    }

    /**
     * Snapshot domain analitik (§43) dengan insight ringkas.
     *
     * @return array<string, mixed>
     */
    public function captureDomainSnapshot(string $domain, ?int $organizationId = null, mixed $actor = null): HrPeopleAnalyticsSnapshot
    {
        $domain = strtoupper($domain);
        $metrics = [];
        foreach ($this->metricsForDomain($domain) as $metricCode) {
            $metrics[$metricCode] = $this->metrics->evaluate($metricCode, ['organization_id' => $organizationId])['value'] ?? null;
        }

        $insights = [];
        foreach ($metrics as $metricCode => $value) {
            if ($value === null) {
                continue;
            }
            if (str_contains($metricCode, 'TURNOVER') && $value > 15) {
                $insights[] = $metricCode.' di atas 15% — periksa unit dengan sinyal attrition tinggi.';
            }
            if (str_contains($metricCode, 'ABSENT') && $value > 5) {
                $insights[] = $metricCode.' di atas 5% — tinjau pola ketidakhadiran.';
            }
        }

        return HrPeopleAnalyticsSnapshot::query()->updateOrCreate(
            ['snapshot_date' => now()->toDateString(), 'granularity' => 'DAILY', 'scope' => $organizationId ? 'ORGANIZATION' : 'GLOBAL',
                'organization_id' => $organizationId, 'domain' => $domain],
            [
                'metrics' => $metrics, 'insights' => $insights,
                'computed_at' => now(),
                'refresh_policy' => (string) $this->ctx->config('analytics.refresh.strategic', 'DAILY'),
            ]
        );
    }

    /**
     * Tren lintas periode dengan perbandingan YoY/MoM/QoQ (§48).
     *
     * @return array<string, mixed>
     */
    public function trends(array $metricCodes, int $periods = 12, array $filters = [], mixed $user = null): array
    {
        $series = [];
        foreach ($metricCodes as $metricCode) {
            $series[$metricCode] = $this->metrics->trend((string) $metricCode, $periods, $filters, $user);
        }

        return ['metrics' => $series, 'periods' => $periods, 'generated_at' => now()->toIso8601String()];
    }

    /**
     * Cakupan analitik: domain, metrik, dan jumlah snapshot.
     *
     * @return array<string, mixed>
     */
    public function coverage(): array
    {
        $domains = (array) $this->ctx->config('analytics.domains', []);
        $rows = [];
        foreach ($domains as $domain) {
            $metrics = $this->metricsForDomain((string) $domain);
            $snapshots = HrPeopleAnalyticsSnapshot::query()->where('domain', $domain)->count();
            $rows[] = ['domain' => $domain, 'metrics' => $metrics, 'available' => $this->metricsAvailable($metrics), 'snapshots' => $snapshots];
        }

        return [
            'domains' => $rows,
            'workforce_snapshots' => HrWorkforceSnapshot::query()->count(),
            'metric_snapshots' => HrMetricSnapshot::query()->count(),
            'definitions' => $this->metrics->canonicalMetrics()['metrics'] ?? [],
            'note' => 'Analitik dipisahkan dari beban transaksional; dashboard membaca snapshot/metrik.',
        ];
    }

    /**
     * Metrik per domain — satu sumber kebenaran untuk dashboard & AI.
     *
     * @return array<int, string>
     */
    public function metricsForDomain(string $domain): array
    {
        return match (strtoupper($domain)) {
            'WORKFORCE' => ['HEADCOUNT', 'HEADCOUNT_GROWTH', 'OPEN_POSITIONS', 'SPAN_OF_CONTROL'],
            'RETENTION' => ['TURNOVER_RATE', 'VOLUNTARY_TURNOVER', 'AVG_TENURE'],
            'COMPENSATION' => ['COST_PER_EMPLOYEE', 'PAYROLL_TOTAL', 'PAY_COMPRESSION'],
            'ATTENDANCE' => ['ABSENTEEISM_RATE', 'LATE_RATE', 'OVERTIME_HOURS'],
            'PERFORMANCE' => ['PERFORMANCE_AVERAGE', 'HIGH_PERFORMER_RATE'],
            'SKILLS' => ['SKILL_COVERAGE', 'CRITICAL_SKILL_GAP'],
            'LEARNING' => ['TRAINING_COMPLETION', 'TRAINING_HOURS'],
            'MOBILITY' => ['INTERNAL_MOBILITY_RATE', 'PROMOTION_RATE'],
            'SUCCESSION' => ['SUCCESSION_COVERAGE', 'READY_NOW_RATE'],
            'ENGAGEMENT' => ['ENGAGEMENT_SCORE', 'SURVEY_PARTICIPATION'],
            default => [],
        };
    }

    /**
     * @param  array<int, string>  $metricCodes
     * @return array<int, string>
     */
    private function metricsAvailable(array $metricCodes): array
    {
        $available = [];
        foreach ($metricCodes as $metricCode) {
            $exists = \App\Modules\Hrd\Strategic\Analytics\Models\HrMetricDefinition::query()
                ->where('code', $metricCode)->where('is_active', true)->exists();
            if ($exists) {
                $available[] = $metricCode;
            }
        }

        return $available;
    }
}
