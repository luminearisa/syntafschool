<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use App\Modules\Hrd\Strategic\Workforce\Models\HrPositionPlan;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceCostForecast;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceDemand;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceForecast;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceGap;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforcePlan;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforcePlanVersion;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceResourceRequirement;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceSupply;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Perencanaan tenaga kerja strategis:
 *   Current Workforce → Strategi → Future Demand → Supply → Gap → Rencana Aksi.
 *
 * Supply SELALU dihitung dari data aktual (employee, posisi, kontrak,
 * keterampilan, talent pool). Tidak ada master pegawai kedua di Phase 3.
 */
class WorkforcePlanningService
{
    public function __construct(private readonly Phase3Context $ctx) {}

    public function createPlan(array $data, mixed $actor = null): HrWorkforcePlan
    {
        $horizon = strtoupper((string) ($data['planning_horizon'] ?? $this->ctx->config('planning.default_horizon', 'ANNUAL')));
        $months = (int) ($data['horizon_months'] ?? $this->ctx->config('planning.horizons.'.$horizon, 12));
        $months = $months > 0 ? $months : (int) ($data['horizon_months'] ?? 12);
        $start = Carbon::parse($data['period_start'] ?? now()->startOfYear());

        $plan = HrWorkforcePlan::query()->create([
            'code' => $data['code'] ?? $this->ctx->nextCode('hr_workforce_plans', 'WFP'),
            'name' => $data['name'] ?? 'Rencana Tenaga Kerja '.$start->format('Y'),
            'organization_id' => $data['organization_id'] ?? null,
            'period_start' => $start->toDateString(),
            'period_end' => $data['period_end'] ?? $start->copy()->addMonths($months)->subDay()->toDateString(),
            'planning_horizon' => $horizon,
            'plan_type' => $data['plan_type'] ?? 'HEADCOUNT',
            'status' => $data['status'] ?? 'DRAFT',
            'owner_id' => $data['owner_id'] ?? $this->ctx->employeeFor($actor)?->getKey(),
            'version' => 1,
            'assumptions' => $data['assumptions'] ?? [],
            'strategy_reference' => $data['strategy_reference'] ?? null,
            'budget_amount' => $data['budget_amount'] ?? null,
            'currency' => $data['currency'] ?? $this->ctx->config('planning.currency', 'IDR'),
            'notes' => $data['notes'] ?? null,
            'meta' => ['horizon_months' => $months],
        ]);

        HrWorkforcePlanVersion::query()->create([
            'plan_id' => $plan->getKey(), 'version' => 1, 'status' => $plan->status,
            'assumptions' => $plan->assumptions, 'created_by' => $this->ctx->employeeFor($actor)?->getKey(),
        ]);

        $this->ctx->audit('strategic.workforce_plan.created', HrWorkforcePlan::class, $plan->getKey(), [
            'code' => $plan->code, 'horizon' => $horizon, 'months' => $months,
        ], null, $actor);

        $this->ctx->event('STRATEGIC_PLANNING', 'WorkforcePlanCreated', [
            'title' => 'Rencana tenaga kerja dibuat', 'description' => $plan->name,
        ], null, HrWorkforcePlan::class, $plan->getKey(), $actor);

        return $plan->refresh();
    }

    /**
     * Revisi menghasilkan versi baru; versi lama tetap utuh untuk audit.
     */
    public function revise(HrWorkforcePlan $plan, array $changes, mixed $actor = null): HrWorkforcePlan
    {
        $version = (int) $plan->version + 1;
        $plan->forceFill([
            'version' => $version,
            'name' => $changes['name'] ?? $plan->name,
            'assumptions' => $changes['assumptions'] ?? $plan->assumptions,
            'budget_amount' => array_key_exists('budget_amount', $changes) ? $changes['budget_amount'] : $plan->budget_amount,
            'status' => $changes['status'] ?? $plan->status,
        ])->save();

        HrWorkforcePlanVersion::query()->create([
            'plan_id' => $plan->getKey(), 'version' => $version, 'status' => $plan->status,
            'assumptions' => $plan->assumptions, 'snapshot' => $this->dashboard($plan),
            'created_by' => $this->ctx->employeeFor($actor)?->getKey(), 'activated_at' => now(),
        ]);

        $this->ctx->audit('strategic.workforce_plan.revised', HrWorkforcePlan::class, $plan->getKey(), [
            'version' => $version, 'changes' => array_keys($changes),
        ], null, $actor);

        return $plan->refresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, HrWorkforceDemand>
     */
    public function addDemand(HrWorkforcePlan $plan, array $lines, mixed $actor = null): array
    {
        $created = [];
        foreach ($lines as $line) {
            $created[] = HrWorkforceDemand::query()->create([
                'plan_id' => $plan->getKey(),
                'version' => (int) $plan->version,
                'organization_id' => $line['organization_id'] ?? $plan->organization_id,
                'department_id' => $line['department_id'] ?? null,
                'position_id' => $line['position_id'] ?? null,
                'job_id' => $line['job_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
                'period_start' => $line['period_start'] ?? $plan->period_start,
                'period_end' => $line['period_end'] ?? $plan->period_end,
                'required_headcount' => (int) ($line['required_headcount'] ?? 0),
                'required_skills' => $line['required_skills'] ?? [],
                'required_capacity' => $line['required_capacity'] ?? null,
                'budget_amount' => $line['budget_amount'] ?? null,
                'driver' => $line['driver'] ?? 'OPERATIONAL',
                'driver_reference' => $line['driver_reference'] ?? null,
                'priority' => $line['priority'] ?? 'MEDIUM',
                'status' => $line['status'] ?? 'PLANNED',
                'assumptions' => $line['assumptions'] ?? [],
                'created_by' => $this->ctx->employeeFor($actor)?->getKey(),
            ]);
        }

        $total = array_sum(array_map(fn ($row): int => (int) $row->required_headcount, $created));
        $this->ctx->audit('strategic.workforce_demand.recorded', HrWorkforcePlan::class, $plan->getKey(), [
            'lines' => count($created), 'total_required_headcount' => $total,
        ], null, $actor);

        return $created;
    }

    /**
     * Supply aktual: pegawai aktif, lowongan, pensiun, kontrak berakhir,
     * attrition historis, mobilitas internal, keterampilan tersedia, talent pool.
     */
    public function captureSupply(HrWorkforcePlan $plan, array $filter = [], mixed $actor = null): HrWorkforceSupply
    {
        $organizationId = $filter['organization_id'] ?? null;
        $departmentId = $filter['department_id'] ?? null;
        $asOf = Carbon::parse($filter['as_of'] ?? now());
        $horizonMonths = (int) ($plan->meta['horizon_months'] ?? 12);

        $headcountQuery = HrEmployee::query()->where('is_active', true);
        foreach (['organization_id', 'department_id', 'position_id', 'location_id', 'worker_type'] as $column) {
            if (! empty($filter[$column])) {
                $headcountQuery->where($column, $filter[$column]);
            }
        }
        $active = (int) $headcountQuery->count();

        $openPositions = (int) $this->ctx->safe(function () use ($organizationId, $departmentId): int {
            $query = HrPosition::query()->whereIn('status', ['ACTIVE', 'OPEN', 'APPROVED', 'VACANT']);
            $organizationId && $query->where('organization_id', $organizationId);
            $departmentId && $query->where('department_id', $departmentId);

            return (int) $query->get()->filter(fn (HrPosition $p): bool => (int) $p->filled_headcount < (int) $p->authorized_headcount)->count();
        }, 0);

        // Pensiun: memakai tanggal lahir di master Person (Phase 1), bukan data baru.
        $expectedRetirements = (int) $this->ctx->safe(function () use ($horizonMonths, $filter): int {
            if (! $this->ctx->hasTable('hr_people')) {
                return 0;
            }
            $limit = now()->subYears(58)->toDateString();
            $query = DB::table('hr_people')->whereNotNull('date_of_birth')->where('date_of_birth', '<=', $limit);

            return (int) $query->count();
        }, 0);

        $contractExpiries = (int) $this->ctx->probeCount('hr_contracts', [
            'status' => 'ACTIVE',
        ], 0);
        $contractExpiries = (int) $this->ctx->safe(function () use ($horizonMonths): int {
            if (! $this->ctx->hasTable('hr_contracts')) {
                return 0;
            }
            $cursor = Carbon::now()->addMonths($horizonMonths);

            return (int) DB::table('hr_contracts')->whereNotNull('end_date')
                ->whereBetween('end_date', [now()->toDateString(), $cursor->toDateString()])->count();
        }, $contractExpiries);

        $attritionRate = $this->historicalAttritionRate($horizonMonths);
        $internalMobility = (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_internal_job_applications')->where('created_at', '>=', now()->subYear())->count(), 0);
        $talentPoolSize = (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_talent_pool_members')->where('status', 'ACTIVE')->count(), 0);
        $weeklyHours = (float) $this->ctx->config('capacity.weekly_hours', 40);
        $capacityHours = round($active * $weeklyHours * ($horizonMonths / 12) * 52 * 0.9, 2);

        $supply = HrWorkforceSupply::query()->create([
            'plan_id' => $plan->getKey(),
            'version' => (int) $plan->version,
            'organization_id' => $organizationId,
            'department_id' => $departmentId,
            'position_id' => $filter['position_id'] ?? null,
            'location_id' => $filter['location_id'] ?? null,
            'as_of' => $asOf->toDateString(),
            'active_headcount' => $active,
            'open_positions' => $openPositions,
            'expected_retirements' => $expectedRetirements,
            'contract_expiries' => $contractExpiries,
            'expected_attrition' => round($attritionRate, 4),
            'internal_mobility' => $internalMobility,
            'available_skills' => $this->availableSkillCounts($filter),
            'talent_pool_size' => $talentPoolSize,
            'capacity_hours' => $capacityHours,
            'cost_amount' => $this->averageMonthlyCost() !== 0.0 ? $this->ctx->money($this->averageMonthlyCost() * $active) : null,
            'breakdown' => [
                'active' => $active, 'open_positions' => $openPositions, 'expected_retirements' => $expectedRetirements,
                'contract_expiries' => $contractExpiries, 'attrition_rate' => round($attritionRate, 4),
                'internal_mobility' => $internalMobility, 'talent_pool' => $talentPoolSize,
            ],
        ]);

        $this->ctx->audit('strategic.workforce_supply.captured', HrWorkforcePlan::class, $plan->getKey(), [
            'active_headcount' => $active, 'open_positions' => $openPositions,
            'attrition_rate' => round($attritionRate, 4), 'as_of' => $asOf->toDateString(),
        ], null, $actor);

        return $supply;
    }

    /**
     * Gap analysis enam dimensi: headcount, skill, cost, capacity, leadership, location.
     *
     * @return array{gaps:array<int, array<string, mixed>>, summary:array<string, mixed>}
     */
    public function analyseGaps(HrWorkforcePlan $plan, mixed $actor = null): array
    {
        $demands = HrWorkforceDemand::query()->where('plan_id', $plan->getKey())
            ->where('version', (int) $plan->version)->get();
        $supply = HrWorkforceSupply::query()->where('plan_id', $plan->getKey())
            ->where('version', (int) $plan->version)->orderByDesc('id')->first();

        $activeByPosition = HrEmployee::query()->where('is_active', true)
            ->selectRaw('position_id, count(*) as total')->groupBy('position_id')->pluck('total', 'position_id');

        $gaps = [];
        $requiredTotal = 0;
        $availableTotal = (int) ($supply->active_headcount ?? HrEmployee::query()->where('is_active', true)->count());

        foreach ($demands as $demand) {
            $required = (int) $demand->required_headcount;
            $requiredTotal += $required;
            $positionId = $demand->position_id;
            $available = (int) ($positionId ? ($activeByPosition[$positionId] ?? 0) : ($supply->active_headcount ?? 0));
            $delta = $required - $available;

            if ($delta !== 0) {
                $gaps[] = $this->storeGap($plan, [
                    'gap_type' => 'HEADCOUNT',
                    'dimension_key' => $positionId ? 'POSITION:'.$positionId : 'ALL',
                    'organization_id' => $demand->organization_id,
                    'department_id' => $demand->department_id,
                    'position_id' => $positionId,
                    'required_value' => $required,
                    'available_value' => $available,
                    'gap_value' => $delta,
                    'severity' => $this->severityFor(abs($delta), max($required, 1)),
                    'recommended_actions' => $delta > 0
                        ? ['Rekrutmen '.$delta.' orang', 'Pertimbangkan redeployment internal', 'Cek pelatihan untuk menutup gap keterampilan']
                        : ['Tahan rekrutmen', 'Redeploy ke unit yang kekurangan'],
                    'evidence' => ['driver' => $demand->driver, 'driver_reference' => $demand->driver_reference],
                ]);
            }

            foreach ((array) ($demand->required_skills ?? []) as $skill) {
                $code = (string) ($skill['code'] ?? $skill['skill_code'] ?? '');
                $levelOrdinal = (int) ($skill['required_ordinal'] ?? 0);
                $needed = (int) ($skill['headcount'] ?? $required);
                if ($code === '') {
                    continue;
                }
                $availableSkill = $this->availableSkillCounts(['skill_code' => $code, 'min_ordinal' => $levelOrdinal]);
                $have = (int) ($availableSkill[$code] ?? 0);
                $gap = $needed - $have;
                if ($gap === 0) {
                    continue;
                }
                $gaps[] = $this->storeGap($plan, [
                    'gap_type' => 'SKILL',
                    'dimension_key' => $code,
                    'organization_id' => $demand->organization_id,
                    'department_id' => $demand->department_id,
                    'position_id' => $positionId,
                    'required_value' => $needed,
                    'available_value' => $have,
                    'gap_value' => $gap,
                    'severity' => $this->severityFor(abs($gap), max($needed, 1)),
                    'recommended_actions' => [
                        'BUILD: pelatihan untuk '.$gap.' pegawai',
                        'BUY: rekrut '.$gap.' kandidat dengan '.$code,
                        'BORROW: kontraktor/partner sementara',
                    ],
                    'evidence' => ['skill' => $code, 'required_ordinal' => $levelOrdinal, 'available_ordinal_filter' => $levelOrdinal],
                ]);
            }

            if ($demand->required_capacity && $supply?->capacity_hours) {
                $gapCapacity = (float) $demand->required_capacity - (float) $supply->capacity_hours;
                $gaps[] = $this->storeGap($plan, [
                    'gap_type' => 'CAPACITY',
                    'dimension_key' => 'HOURS',
                    'organization_id' => $demand->organization_id,
                    'department_id' => $demand->department_id,
                    'required_value' => (float) $demand->required_capacity,
                    'available_value' => (float) $supply->capacity_hours,
                    'gap_value' => round($gapCapacity, 2),
                    'severity' => $this->severityFor(abs($gapCapacity), max((float) $demand->required_capacity, 1.0)),
                    'recommended_actions' => $gapCapacity > 0
                        ? ['Tambah jam mengajar dari pegawai yang belum optimal', 'Rekrut/outsource beban puncak']
                        : ['Beban tersedia masih mencukupi'],
                    'evidence' => ['available_capacity_hours' => (float) $supply->capacity_hours],
                ]);
            }
        }

        $demandedBudget = (float) $demands->sum(fn ($d): float => (float) ($d->budget_amount ?? 0));
        $projectedCost = $this->ctx->money($this->averageMonthlyCost() * max($requiredTotal, 1));
        if ($demandedBudget > 0 || $projectedCost > 0) {
            $gaps[] = $this->storeGap($plan, [
                'gap_type' => 'COST',
                'dimension_key' => 'PERIOD_TOTAL',
                'required_value' => $demandedBudget,
                'available_value' => $projectedCost,
                'gap_value' => round($projectedCost - $demandedBudget, 2),
                'severity' => $this->severityFor(abs($projectedCost - $demandedBudget), max($demandedBudget, 1.0)),
                'recommended_actions' => $projectedCost > $demandedBudget
                    ? ['Sesuaikan anggaran', 'Ubah komposisi junior/senior', 'Tunda perekrutan non-kritis']
                    : ['Anggaran masih tersedia untuk akselerasi perekrutan'],
                'evidence' => ['budget_lines' => $demandedBudget, 'projected' => $projectedCost],
            ]);
        }

        $leadership = $this->ctx->safe(function (): array {
            if (! $this->ctx->hasTable('hr_critical_positions')) {
                return ['critical' => 0, 'covered' => 0];
            }
            $critical = DB::table('hr_critical_positions')->where('status', 'ACTIVE')->count();
            $covered = DB::table('hr_critical_positions')->where('status', 'ACTIVE')
                ->whereRaw('(select count(*) from hr_succession_plans p where p.critical_position_id = hr_critical_positions.id) > 0')
                ->count();

            return ['critical' => (int) $critical, 'covered' => (int) $covered];
        }, ['critical' => 0, 'covered' => 0]);

        if (($leadership['critical'] ?? 0) > 0) {
            $gapLeadership = (int) $leadership['critical'] - (int) $leadership['covered'];
            if ($gapLeadership > 0) {
                $gaps[] = $this->storeGap($plan, [
                    'gap_type' => 'LEADERSHIP',
                    'dimension_key' => 'CRITICAL_POSITIONS',
                    'required_value' => (int) $leadership['critical'],
                    'available_value' => (int) $leadership['covered'],
                    'gap_value' => $gapLeadership,
                    'severity' => $this->severityFor($gapLeadership, max((int) $leadership['critical'], 1)),
                    'recommended_actions' => ['Susun rencana suksesi', 'Percepat pengembangan kandidat internal'],
                    'evidence' => $leadership,
                ]);
            }
        }

        $byLocation = HrEmployee::query()->where('is_active', true)
            ->selectRaw('location_id, count(*) as total')->groupBy('location_id')->pluck('total', 'location_id');
        foreach ($byLocation as $locationId => $count) {
            $required = (int) $demands->where('location_id', $locationId)->sum('required_headcount');
            if ($required > 0 && $required !== (int) $count) {
                $gaps[] = $this->storeGap($plan, [
                    'gap_type' => 'LOCATION',
                    'dimension_key' => 'LOCATION:'.($locationId ?? 'GLOBAL'),
                    'required_value' => $required,
                    'available_value' => (int) $count,
                    'gap_value' => $required - (int) $count,
                    'severity' => $this->severityFor(abs($required - (int) $count), max($required, 1)),
                    'recommended_actions' => ['Selaraskan penempatan antar lokasi'],
                    'evidence' => ['location_id' => $locationId],
                ]);
            }
        }

        $summary = [
            'required_headcount' => $requiredTotal,
            'available_headcount' => $availableTotal,
            'headcount_gap' => $requiredTotal - $availableTotal,
            'by_type' => collect($gaps)->groupBy('gap_type')->map->count()->all(),
            'severity' => collect($gaps)->groupBy('severity')->map->count()->all(),
            'gaps' => count($gaps),
        ];

        $this->ctx->audit('strategic.workforce_gap.analysed', HrWorkforcePlan::class, $plan->getKey(), $summary, null, $actor);
        $this->ctx->event('STRATEGIC_PLANNING', 'WorkforceGapAnalysed', [
            'title' => 'Analisis gap tenaga kerja', 'description' => 'Gap headcount: '.$summary['headcount_gap'],
            'meta' => $summary,
        ], null, HrWorkforcePlan::class, $plan->getKey(), $actor);

        return ['gaps' => $gaps, 'summary' => $summary];
    }

    /**
     * Proyeksi biaya tenaga kerja per bulan: payroll sekarang + rencana
     * perekrutan + kenaikan gaji + promosi + benefit − attrition.
     *
     * @return array{rows:array<int, array<string, mixed>>, totals:array<string, mixed>}
     */
    public function forecastCost(HrWorkforcePlan $plan, array $assumptions = [], mixed $actor = null): array
    {
        $headcount = (int) HrEmployee::query()->where('is_active', true)->count();
        $avgCost = $this->averageMonthlyCost();
        $salaryDelta = (float) ($assumptions['salary_increase_percent'] ?? 0);
        $promotionDelta = (float) ($assumptions['promotion_percent'] ?? 0);
        $hiringDelta = (int) ($assumptions['planned_hires'] ?? 0);
        $attritionRate = (float) ($assumptions['attrition_rate'] ?? $this->historicalAttritionRate(12));
        $benefitPerEmployee = $this->benefitCostPerEmployee();
        $recruitmentCostPerHire = (float) ($assumptions['recruitment_cost_per_hire'] ?? $this->ctx->config('cost.recruitment_per_hire', 2500000));
        $trainingCostPerEmployee = (float) ($assumptions['training_cost_per_employee'] ?? $this->ctx->config('cost.training_per_employee', 750000));
        $monthlyBudget = (float) ($assumptions['budget_monthly'] ?? ($plan->budget_amount ? (float) $plan->budget_amount : 0.0));

        $periods = $this->ctx->periodsBetween($plan->period_start, $plan->period_end, 60);
        $rows = [];
        $totalSalary = $totalBenefit = $totalRecruitment = $totalTraining = $totalTotal = 0.0;
        $runningHeadcount = $headcount;

        foreach ($periods as $index => $period) {
            $exitThisMonth = (int) round($runningHeadcount * ($attritionRate > 1 ? $attritionRate / 100 : $attritionRate) / 12);
            $hiresThisMonth = (int) ceil($hiringDelta / max(count($periods), 1));
            $runningHeadcount = max(0, $runningHeadcount - $exitThisMonth + $hiresThisMonth);

            $salary = $this->ctx->money($runningHeadcount * $avgCost * (1 + ($salaryDelta / 100)) * (1 + ($promotionDelta / 100) * 0.5));
            $benefit = $this->ctx->money($runningHeadcount * $benefitPerEmployee);
            $recruitment = $this->ctx->money($hiresThisMonth * $recruitmentCostPerHire);
            $training = $this->ctx->money($runningHeadcount * $trainingCostPerEmployee / 12);
            $total = $this->ctx->money($salary + $benefit + $recruitment + $training);
            $budget = $monthlyBudget > 0 ? $this->ctx->money($monthlyBudget) : null;

            $rows[] = [
                'period' => $period,
                'headcount' => $runningHeadcount,
                'salary_amount' => $salary,
                'allowance_amount' => 0.0,
                'benefit_amount' => $benefit,
                'overtime_amount' => 0.0,
                'recruitment_amount' => $recruitment,
                'training_amount' => $training,
                'severance_reference_amount' => $this->ctx->money($exitThisMonth * $avgCost),
                'total_amount' => $total,
                'budget_amount' => $budget,
                'variance_amount' => $budget !== null ? $this->ctx->money($total - $budget) : null,
                'assumptions' => $assumptions,
            ];

            $totalSalary += $salary;
            $totalBenefit += $benefit;
            $totalRecruitment += $recruitment;
            $totalTraining += $training;
            $totalTotal += $total;
            $budget !== null && $this->persistCostRow($plan, $rows[array_key_last($rows)], $budget);
        }

        if ($monthlyBudget <= 0 && $rows !== []) {
            foreach ($rows as $row) {
                $this->persistCostRow($plan, $row, null);
            }
        }

        $totals = [
            'months' => count($rows),
            'salary' => $this->ctx->money($totalSalary),
            'benefit' => $this->ctx->money($totalBenefit),
            'recruitment' => $this->ctx->money($totalRecruitment),
            'training' => $this->ctx->money($totalTraining),
            'total' => $this->ctx->money($totalTotal),
            'budget' => $monthlyBudget > 0 ? $this->ctx->money($monthlyBudget * count($rows)) : null,
            'variance' => $monthlyBudget > 0 ? $this->ctx->money($totalTotal - ($monthlyBudget * count($rows))) : null,
            'average_per_employee' => $headcount > 0 ? $this->ctx->money($totalTotal / max(count($rows), 1) / $headcount) : 0.0,
            'finance_integration' => $monthlyBudget > 0 ? 'BUDGET_SUPPLIED' : 'FINANCE_PROVIDER_PENDING',
        ];

        $this->ctx->audit('strategic.workforce_cost.forecasted', HrWorkforcePlan::class, $plan->getKey(), $totals, null, $actor);
        $this->ctx->event('STRATEGIC_PLANNING', 'WorkforceCostForecasted', [
            'title' => 'Proyeksi biaya tenaga kerja', 'meta' => $totals,
        ], null, HrWorkforcePlan::class, $plan->getKey(), $actor);

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Optimasi posisi: lowongan, kelebihan, kekurangan, posisi kritis,
     * duplikasi peran, dan single point of failure.
     *
     * @return array<string, mixed>
     */
    public function optimisePositions(?int $organizationId = null): array
    {
        $query = HrPosition::query();
        $organizationId && $query->where('organization_id', $organizationId);
        $positions = $query->get();

        $filledByPosition = HrEmployee::query()->where('is_active', true)
            ->selectRaw('position_id, count(*) as total')->groupBy('position_id')->pluck('total', 'position_id');

        $vacant = $understaffed = $overstaffed = $critical = [];
        foreach ($positions as $position) {
            $authorized = (int) $position->authorized_headcount;
            $filled = (int) ($filledByPosition[$position->getKey()] ?? $position->filled_headcount ?? 0);
            if ($authorized > 0 && $filled === 0) {
                $vacant[] = ['position_id' => $position->getKey(), 'code' => $position->code, 'title' => $position->title, 'authorized' => $authorized];
            } elseif ($filled < $authorized) {
                $understaffed[] = ['position_id' => $position->getKey(), 'code' => $position->code, 'shortage' => $authorized - $filled];
            } elseif ($filled > $authorized && $authorized > 0) {
                $overstaffed[] = ['position_id' => $position->getKey(), 'code' => $position->code, 'excess' => $filled - $authorized];
            }
            if ((bool) $position->is_critical) {
                $critical[] = ['position_id' => $position->getKey(), 'code' => $position->code, 'title' => $position->title, 'filled' => $filled];
            }
        }

        $duplicates = $positions->groupBy(fn (HrPosition $p): string => mb_strtolower((string) $p->title).'|'.(string) $p->department_id)
            ->filter(fn ($group): bool => $group->count() > 1)
            ->map(fn ($group): array => [
                'title' => $group->first()->title,
                'department_id' => $group->first()->department_id,
                'positions' => $group->pluck('code')->all(),
            ])->values()->all();

        $singlePoint = $this->ctx->safe(function () use ($organizationId): array {
            if (! $this->ctx->hasTable('hr_employee_skills') || ! $this->ctx->hasTable('hr_skills')) {
                return [];
            }
            $rows = DB::table('hr_employee_skills as es')
                ->join('hr_skills as s', 's.id', '=', 'es.skill_id')
                ->join('hr_employees as e', 'e.id', '=', 'es.employee_id')
                ->where('e.is_active', true)
                ->when($organizationId, fn ($q) => $q->where('e.organization_id', $organizationId))
                ->selectRaw('s.code as skill_code, e.department_id as department_id, count(distinct es.employee_id) as holders')
                ->groupBy('s.code', 'e.department_id')
                ->havingRaw('count(distinct es.employee_id) <= 1')
                ->limit(20)
                ->get();

            return $rows->map(fn ($row): array => [
                'skill_code' => $row->skill_code, 'department_id' => $row->department_id, 'holders' => (int) $row->holders,
                'reason' => 'Hanya satu pegawai aktif memegang keterampilan ini di unit tersebut',
            ])->all();
        }, []);

        return [
            'positions' => $positions->count(),
            'vacant' => $vacant,
            'understaffed' => $understaffed,
            'overstaffed' => $overstaffed,
            'critical' => $critical,
            'duplicate_roles' => $duplicates,
            'single_point_of_failure' => $singlePoint,
            'summary' => [
                'vacant' => count($vacant), 'understaffed' => count($understaffed), 'overstaffed' => count($overstaffed),
                'critical' => count($critical), 'duplicate_roles' => count($duplicates), 'single_point_of_failure' => count($singlePoint),
            ],
        ];
    }

    /**
     * Kapasitas & utilisasi: jam tersedia vs jam yang diminta/dipakai.
     * Utilisasi bukan ukuran produktivitas — selalu disajikan bersama konteks.
     *
     * @return array<string, mixed>
     */
    public function capacity(?int $organizationId = null, ?string $period = null, mixed $user = null): array
    {
        $period = $period ?? now()->format('Y-m');
        $employees = $this->ctx->scopedEmployees($user, $organizationId)->where('is_active', true)->get();
        $weeklyHours = (float) $this->ctx->config('capacity.weekly_hours', 40);
        $weeks = 4.33;

        $allocated = $this->ctx->safe(function () use ($employees, $period): array {
            if (! $this->ctx->hasTable('hr_project_assignments')) {
                return [];
            }

            return DB::table('hr_project_assignments')->whereIn('employee_id', $employees->pluck('id')->all())
                ->whereIn('status', ['PLANNED', 'ACTIVE'])
                ->selectRaw('employee_id, sum(allocation_percent) as allocation')
                ->groupBy('employee_id')->pluck('allocation', 'employee_id')->all();
        }, []);

        $rows = [];
        $totalAvailable = $totalAssigned = 0.0;
        foreach ($employees as $employee) {
            $available = round($weeklyHours * $weeks, 2);
            $allocationPercent = (float) ($allocated[$employee->getKey()] ?? 0);
            $assigned = round($available * min(max($allocationPercent, 0), 150) / 100, 2);
            $totalAvailable += $available;
            $totalAssigned += $assigned;
            $rows[] = [
                'employee_id' => $employee->getKey(),
                'employee_number' => $employee->employee_number,
                'capacity_hours' => $available,
                'assigned_hours' => $assigned,
                'allocation_percent' => $allocationPercent,
                'utilization_percent' => $this->ctx->percent($assigned, $available),
                'over_allocated' => $allocationPercent > (float) $this->ctx->config('marketplace.max_allocation_percent', 100),
            ];
        }

        $actualHours = $this->ctx->safe(function () use ($employees, $period): ?float {
            if (! $this->ctx->hasTable('hr_attendance_records')) {
                return null;
            }

            return (float) DB::table('hr_attendance_records')
                ->whereIn('employee_id', $employees->pluck('id')->all())
                ->where('date', 'like', $period.'%')
                ->sum('worked_minutes') / 60;
        }, null);

        return [
            'period' => $period,
            'employees' => count($rows),
            'available_hours' => $this->ctx->money($totalAvailable),
            'assigned_hours' => $this->ctx->money($totalAssigned),
            'actual_hours' => $actualHours !== null ? $this->ctx->money($actualHours) : null,
            'utilization_percent' => $this->ctx->percent($totalAssigned, $totalAvailable),
            'note' => 'Utilisasi adalah sinyal analitik, bukan kesimpulan produktivitas.',
            'rows' => $rows,
        ];
    }

    /**
     * Kebutuhan sumber daya fisik dari proyeksi headcount (§87): laptop, meja,
     * kartu akses, seragam, workspace, lisensi. Eksekusinya di modul Asset.
     *
     * @param  array<int, array{type:string,quantity:int,unit_cost:float}>  $catalogue
     * @return array<string, mixed>
     */
    public function planResources(HrWorkforcePlan $plan, array $catalogue, mixed $actor = null): array
    {
        $required = (int) HrWorkforceDemand::query()->where('plan_id', $plan->getKey())
            ->where('version', (int) $plan->version)->sum('required_headcount');
        $active = (int) HrEmployee::query()->where('is_active', true)->count();
        $delta = max($required - $active, 0);

        $rows = [];
        foreach ($catalogue as $item) {
            $quantity = (int) ($item['quantity'] ?? $delta);
            $unitCost = (float) ($item['unit_cost'] ?? 0);
            $rows[] = HrWorkforceResourceRequirement::query()->create([
                'plan_id' => $plan->getKey(),
                'resource_type' => strtoupper((string) $item['type']),
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $this->ctx->money($quantity * $unitCost),
                'needed_on' => $item['needed_on'] ?? $plan->period_start,
                'source' => 'HEADCOUNT_FORECAST',
                'status' => 'PLANNED',
            ]);
        }

        $total = (float) collect($rows)->sum('total_cost');
        $this->ctx->audit('strategic.workforce_resource.planned', HrWorkforcePlan::class, $plan->getKey(), [
            'headcount_delta' => $delta, 'items' => count($rows), 'total_cost' => $total,
        ], null, $actor);

        return ['headcount_delta' => $delta, 'items' => $rows, 'total_cost' => $this->ctx->money($total)];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(HrWorkforcePlan $plan): array
    {
        $demands = HrWorkforceDemand::query()->where('plan_id', $plan->getKey())->where('version', (int) $plan->version)->get();
        $supply = HrWorkforceSupply::query()->where('plan_id', $plan->getKey())->orderByDesc('id')->first();
        $gaps = HrWorkforceGap::query()->where('plan_id', $plan->getKey())->get();
        $costs = HrWorkforceCostForecast::query()->where('plan_id', $plan->getKey())->orderBy('period')->get();

        return [
            'plan' => [
                'code' => $plan->code, 'name' => $plan->name, 'status' => $plan->status,
                'version' => (int) $plan->version, 'horizon' => $plan->planning_horizon,
                'period' => $plan->period_start?->format('Y-m-d').' → '.$plan->period_end?->format('Y-m-d'),
            ],
            'demand' => [
                'lines' => $demands->count(),
                'required_headcount' => (int) $demands->sum('required_headcount'),
                'budget' => $this->ctx->money((float) $demands->sum(fn ($d) => (float) ($d->budget_amount ?? 0))),
            ],
            'supply' => $supply ? $supply->breakdown : null,
            'gaps' => [
                'total' => $gaps->count(),
                'by_type' => $gaps->groupBy('gap_type')->map->count()->all(),
                'severity' => $gaps->groupBy('severity')->map->count()->all(),
            ],
            'cost' => [
                'months' => $costs->count(),
                'total' => $this->ctx->money((float) $costs->sum('total_amount')),
                'budget' => $this->ctx->money((float) $costs->sum('budget_amount')),
                'variance' => $this->ctx->money((float) $costs->sum('variance_amount')),
                'trend' => $this->ctx->trend($costs->pluck('total_amount')->map(fn ($v): float => (float) $v)->all()),
            ],
            'forecast' => HrWorkforceForecast::query()->where('plan_id', $plan->getKey())->orderBy('period')->get()
                ->groupBy('forecast_type')->map(fn ($rows): array => [
                    'points' => $rows->count(),
                    'latest' => (float) $rows->last()->value,
                    'confidence' => (float) $rows->last()->confidence,
                ])->all(),
            'position_plans' => HrPositionPlan::query()->where('plan_id', $plan->getKey())->count(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /* ------------------------------------------------------------------ */

    private function storeGap(HrWorkforcePlan $plan, array $data): array
    {
        $row = HrWorkforceGap::query()->create(array_merge([
            'plan_id' => $plan->getKey(),
            'version' => (int) $plan->version,
            'status' => 'OPEN',
        ], $data));

        return [
            'id' => $row->getKey(), 'gap_type' => $row->gap_type, 'dimension_key' => $row->dimension_key,
            'required' => (float) $row->required_value, 'available' => (float) $row->available_value,
            'gap' => (float) $row->gap_value, 'severity' => $row->severity,
            'recommended_actions' => $row->recommended_actions, 'evidence' => $row->evidence,
        ];
    }

    private function persistCostRow(HrWorkforcePlan $plan, array $row, ?float $budget): void
    {
        HrWorkforceCostForecast::query()->updateOrCreate(
            ['plan_id' => $plan->getKey(), 'period' => $row['period'], 'scenario_id' => null],
            array_merge($row, ['organization_id' => $plan->organization_id, 'generated_at' => now()])
        );
    }

    private function severityFor(float $magnitude, float $base): string
    {
        $ratio = $base > 0 ? $magnitude / $base : 0.0;

        return match (true) {
            $ratio >= 0.5 => 'CRITICAL',
            $ratio >= 0.25 => 'HIGH',
            $ratio >= 0.1 => 'MEDIUM',
            default => 'LOW',
        };
    }

    /**
     * Rata-rata biaya tenaga kerja per pegawai per bulan dari payroll final.
     */
    public function averageMonthlyCost(): float
    {
        $fromPayroll = $this->ctx->safe(function (): float {
            if (! $this->ctx->hasTable('hr_payroll_items')) {
                return 0.0;
            }
            // Skema Phase 2: hr_payroll_items.run_id → hr_payroll_runs.
            $runId = DB::table('hr_payroll_runs')->whereIn('status', ['FINALIZED', 'APPROVED', 'PAID'])
                ->orderByDesc('id')->value('id');
            if (! $runId) {
                return 0.0;
            }

            return (float) DB::table('hr_payroll_items')->where('run_id', $runId)->avg('net_amount');
        }, 0.0);

        return $fromPayroll > 0 ? $this->ctx->money($fromPayroll) : (float) $this->ctx->config('cost.fallback_monthly_per_employee', 5000000);
    }

    public function benefitCostPerEmployee(): float
    {
        $fromBenefits = $this->ctx->safe(function (): float {
            if (! $this->ctx->hasTable('hr_benefit_enrollments')) {
                return 0.0;
            }

            return (float) DB::table('hr_benefit_enrollments')->where('status', 'ACTIVE')->avg('employer_amount');
        }, 0.0);

        return $fromBenefits > 0
            ? $this->ctx->money($fromBenefits)
            : $this->ctx->money($this->averageMonthlyCost() * (float) $this->ctx->config('cost.benefit_ratio', 0.12));
    }

    /**
     * Attrition historis dari data aktual (pegawai berhenti 12 bulan terakhir).
     */
    public function historicalAttritionRate(int $months = 12): float
    {
        $leavers = (int) $this->ctx->safe(fn (): int => HrEmployee::query()
            ->whereNotNull('termination_date')
            ->where('termination_date', '>=', now()->subMonths($months)->toDateString())
            ->count(), 0);
        $avgHeadcount = (int) $this->ctx->safe(fn (): int => (int) HrEmployee::query()->where('is_active', true)->count(), 0);

        if ($fromSnapshot = $this->ctx->safe(function () use ($months): float {
            if (! $this->ctx->hasTable('hr_attrition_snapshots')) {
                return 0.0;
            }

            return (float) DB::table('hr_attrition_snapshots')->where('dimension', 'DEPARTMENT')
                ->where('period', '>=', now()->subMonths($months)->format('Y-m'))->avg('turnover_rate');
        }, 0.0)) {
            return round($fromSnapshot, 4);
        }

        return $avgHeadcount > 0 ? round($leavers / $avgHeadcount, 4) : 0.0;
    }

    /**
     * @return array<string, int>
     */
    private function availableSkillCounts(array $filter = []): array
    {
        return $this->ctx->safe(function () use ($filter): array {
            if (! $this->ctx->hasTable('hr_employee_skills') || ! $this->ctx->hasTable('hr_skills')) {
                return [];
            }
            $levels = (array) $this->ctx->config('skills.levels', []);
            $minOrdinal = (int) ($filter['min_ordinal'] ?? 0);
            $rows = DB::table('hr_employee_skills as es')
                ->join('hr_skills as s', 's.id', '=', 'es.skill_id')
                ->join('hr_employees as e', 'e.id', '=', 'es.employee_id')
                ->where('e.is_active', true)
                ->when(! empty($filter['skill_code']), fn ($q) => $q->where('s.code', $filter['skill_code']))
                ->when(! empty($filter['organization_id']), fn ($q) => $q->where('e.organization_id', $filter['organization_id']))
                ->when(! empty($filter['department_id']), fn ($q) => $q->where('e.department_id', $filter['department_id']))
                ->selectRaw('s.code as code, es.proficiency_level_code as level, count(distinct es.employee_id) as total')
                ->groupBy('s.code', 'es.proficiency_level_code')
                ->get();
            $counts = [];
            foreach ($rows as $row) {
                $ordinal = (int) ($levels[strtoupper((string) $row->level)] ?? 0);
                if ($ordinal < $minOrdinal) {
                    continue;
                }
                $counts[(string) $row->code] = ($counts[(string) $row->code] ?? 0) + (int) $row->total;
            }

            return $counts;
        }, []);
    }
}
