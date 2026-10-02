<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Governance\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Strategic\Analytics\Services\MetricEngine;
use App\Modules\Hrd\Strategic\Analytics\Services\PeopleAnalyticsService;
use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrAction;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use App\Modules\Hrd\Strategic\Succession\Services\SuccessionService;
use Illuminate\Support\Facades\DB;

/**
 * Strategic HR Scorecard, Executive Dashboard, dan Board/Yayasan View
 * (§79, §80, §81, §157).
 *
 * Tidak ada satu "skor HR" tunggal tanpa transparansi komponen: setiap area
 * menampilkan current, target, variance, tren, dan status.
 */
class ScorecardService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly MetricEngine $metrics,
        private readonly PeopleAnalyticsService $analytics,
        private readonly SuccessionService $succession,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scorecard(?int $organizationId = null, array $targets = [], mixed $user = null): array
    {
        $areas = [
            'WORKFORCE' => 'HEADCOUNT',
            'TALENT' => 'HIGH_POTENTIAL_RATE',
            'COST' => 'COST_PER_EMPLOYEE',
            'SKILLS' => 'SKILL_COVERAGE',
            'RETENTION' => 'TURNOVER_RATE',
            'PERFORMANCE' => 'PERFORMANCE_AVERAGE',
            'LEARNING' => 'TRAINING_COMPLETION',
            'MOBILITY' => 'INTERNAL_MOBILITY_RATE',
            'SUCCESSION' => 'SUCCESSION_COVERAGE',
            'ENGAGEMENT' => 'ENGAGEMENT_SCORE',
        ];

        $rows = [];
        foreach ($areas as $area => $metricCode) {
            $evaluation = $this->metrics->evaluate($metricCode, ['organization_id' => $organizationId], null, $user);
            $target = (float) ($targets[$metricCode] ?? $this->defaultTarget($metricCode));
            $current = (float) ($evaluation['value'] ?? 0);
            $lowerIsBetter = in_array($metricCode, ['TURNOVER_RATE', 'ABSENTEEISM_RATE', 'COST_PER_EMPLOYEE'], true);
            $variance = $this->ctx->money($current - $target);
            $onTarget = $lowerIsBetter ? ($current <= $target) : ($current >= $target);

            $rows[] = [
                'area' => $area, 'metric' => $metricCode, 'current' => $current, 'target' => $target,
                'variance' => $variance,
                'trend' => $this->ctx->safe(fn (): array => $this->metrics->trend($metricCode, 6, ['organization_id' => $organizationId], $user)['trend'], []),
                'status' => $onTarget ? 'ON_TARGET' : (((abs($variance) / max($target, 1)) > 0.2) ? 'AT_RISK' : 'WATCH'),
                'source' => $evaluation['source_tables'] ?? [],
            ];
        }

        return [
            'areas' => $rows,
            'summary' => [
                'on_target' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'ON_TARGET')),
                'watch' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'WATCH')),
                'at_risk' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'AT_RISK')),
            ],
            'note' => 'Skor keseluruhan tidak ditampilkan tanpa komponen; setiap area dapat ditelusuri ke definisi metriknya.',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Executive dashboard (§80, §157).
     *
     * @return array<string, mixed>
     */
    public function executive(?int $organizationId = null, mixed $user = null): array
    {
        $headcount = (int) HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q, $org) => $q->where('organization_id', $org))->count();
        $coverage = $this->succession->coverage($organizationId);
        $scorecard = $this->scorecard($organizationId, [], $user);
        $highPotential = (int) $this->ctx->safe(fn (): int => $this->ctx->hasTable('hr_talent_review_results')
            ? (int) DB::table('hr_talent_review_results')->where('potential_rating', '>=', 4)->count() : 0, 0);
        $mobility = (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_internal_job_applications')
            ->where('created_at', '>=', now()->subYear())->count(), 0);
        $skills = $this->metrics->evaluate('CRITICAL_SKILL_GAP', ['organization_id' => $organizationId], null, $user);
        $retention = $this->ctx->safe(fn (): array => $this->ctx->hasTable('hr_retention_signals')
            ? ['high' => (int) DB::table('hr_retention_signals')->where('signal_level', 'HIGH')->count(),
                'medium' => (int) DB::table('hr_retention_signals')->where('signal_level', 'MEDIUM')->count()] : ['high' => 0, 'medium' => 0], ['high' => 0, 'medium' => 0]);
        $training = $this->metrics->evaluate('TRAINING_COMPLETION', ['organization_id' => $organizationId], null, $user);
        $actions = HrStrategicHrAction::query()->orderByDesc('id')->limit(5)->get();

        return [
            'workforce' => [
                'current_headcount' => $headcount,
                'open_positions' => (int) $this->ctx->probeCount('hr_job_openings', ['status' => 'OPEN'], 0),
                'critical_positions' => (int) $coverage['critical_positions'],
                'critical_skill_gap' => (float) ($skills['value'] ?? 0),
            ],
            'talent' => [
                'high_potential' => $highPotential, 'critical_talent' => (int) $coverage['with_successor'],
                'internal_mobility_events' => $mobility, 'succession_coverage_percent' => (float) $coverage['coverage_percent'],
            ],
            'cost' => [
                'current_payroll_monthly' => (float) ($this->metrics->evaluate('PAYROLL_TOTAL', [], null, $user)['value'] ?? 0),
                'cost_per_employee' => (float) ($this->metrics->evaluate('COST_PER_EMPLOYEE', [], null, $user)['value'] ?? 0),
                'budget_variance' => $this->ctx->safe(fn (): ?float => ($row = DB::table('hr_workforce_cost_forecasts')
                    ->whereNotNull('budget_amount')->orderByDesc('period')->first()) ? (float) $row->variance_amount : null, null),
            ],
            'risk' => [
                'attrition_signals' => $retention, 'succession_risk' => (int) $coverage['without_successor'],
                'skill_risk' => (float) ($skills['value'] ?? 0),
                'workforce_risk_signals' => (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_risk_signals')->whereIn('severity', ['HIGH', 'CRITICAL'])->count(), 0),
            ],
            'development' => [
                'training_completion_percent' => (float) ($training['value'] ?? 0),
                'career_mobility' => $mobility,
            ],
            'ai_insights' => [
                'pending_recommendations' => (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_ai_recommendations')->whereIn('status', ['DRAFT', 'PENDING_REVIEW'])->count(), 0),
                'alerts' => (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_alerts')->where('status', 'OPEN')->count(), 0),
                'anomalies' => (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_risk_signals')->where('signal_type', 'PAYROLL_ANOMALY')->count(), 0),
            ],
            'strategic_actions' => $actions->map(fn (HrStrategicHrAction $action): array => [
                'code' => $action->code, 'title' => $action->title, 'status' => $action->status, 'priority' => $action->priority,
            ])->all(),
            'scorecard' => $scorecard,
            'scope' => $organizationId ? 'ORGANIZATION:'.$organizationId : 'GLOBAL',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Board / Yayasan view: bandingkan unit di bawah yayasan (§81).
     *
     * @return array<string, mixed>
     */
    public function foundation(int $limit = 10, mixed $user = null): array
    {
        $organizations = HrOrganization::query()->limit($limit)->get();
        $rows = [];

        foreach ($organizations as $organization) {
            $headcount = (int) HrEmployee::query()->where('is_active', true)->where('organization_id', $organization->getKey())->count();
            $payroll = (float) $this->ctx->safe(fn (): float => $this->ctx->hasTable('hr_payroll_items')
                ? (float) DB::table('hr_payroll_items')->whereIn('employee_id', HrEmployee::query()
                    ->where('organization_id', $organization->getKey())->select('id'))->sum('net_amount') : 0.0, 0.0);
            $teachers = (int) $this->ctx->safe(function () use ($organization): int {
                return (int) HrEmployee::query()->where('is_active', true)
                    ->where('organization_id', $organization->getKey())
                    ->where(function ($q): void {
                        $q->whereRaw("lower(worker_type) in ('teacher','guru','ustadz')")
                            ->orWhereHas('position', fn ($sq) => $sq->where('title', 'like', '%guru%'));
                    })->count();
            }, 0);
            $skillGap = (float) $this->metrics->evaluate('CRITICAL_SKILL_GAP', ['organization_id' => $organization->getKey()])['value'] ?? 0.0;
            $coverage = $this->succession->coverage((int) $organization->getKey());

            $rows[] = [
                'organization_id' => $organization->getKey(), 'name' => $organization->name,
                'headcount' => $headcount, 'teachers' => $teachers,
                'workforce_cost' => $this->ctx->money($payroll),
                'cost_per_head' => $headcount > 0 ? $this->ctx->money($payroll / $headcount) : 0.0,
                'critical_skill_gap' => $skillGap,
                'succession_coverage_percent' => (float) $coverage['coverage_percent'],
                'critical_positions_without_successor' => (int) $coverage['without_successor'],
            ];
        }

        return [
            'organizations' => $rows,
            'highest_cost_per_head' => collect($rows)->sortByDesc('cost_per_head')->first()['name'] ?? null,
            'largest_workforce' => collect($rows)->sortByDesc('headcount')->first()['name'] ?? null,
            'note' => 'Perbandingan antar unit memakai definisi metrik yang sama.',
        ];
    }

    /**
     * Papan analitik lengkap per domain (untuk UI analyst).
     *
     * @return array<string, mixed>
     */
    public function analyticsBoard(?int $organizationId = null, mixed $user = null): array
    {
        $domains = [];
        foreach ((array) $this->ctx->config('analytics.domains', []) as $domain) {
            $metrics = $this->analytics->metricsForDomain((string) $domain);
            $values = [];
            foreach ($metrics as $metricCode) {
                $values[$metricCode] = $this->metrics->evaluate($metricCode, ['organization_id' => $organizationId], null, $user);
            }
            $domains[] = ['domain' => $domain, 'metrics' => $values];
        }

        return ['domains' => $domains, 'coverage' => $this->analytics->coverage()];
    }

    private function defaultTarget(string $metricCode): float
    {
        return match ($metricCode) {
            'TURNOVER_RATE' => 12.0, 'ABSENTEEISM_RATE' => 3.0, 'SUCCESSION_COVERAGE' => 80.0,
            'SKILL_COVERAGE' => 75.0, 'TRAINING_COMPLETION' => 85.0, 'ENGAGEMENT_SCORE' => 75.0,
            'INTERNAL_MOBILITY_RATE' => 8.0, 'PERFORMANCE_AVERAGE' => 3.5, 'HIGH_POTENTIAL_RATE' => 10.0,
            default => 0.0,
        };
    }
}
