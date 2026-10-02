<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforcePlan;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceScenario;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceScenarioAssumption;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceForecast;
use Illuminate\Support\Facades\DB;

/**
 * Scenario & What-If Engine.
 *
 * Skenario adalah SIMULASI: tidak pernah mengubah employee, posisi, gaji, atau
 * organisasi aktual. Yang tersimpan hanya asumsi, hasil, dan jejak audit.
 */
class ScenarioService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly WorkforcePlanningService $planning,
    ) {}

    public function createScenario(HrWorkforcePlan $plan, array $data, mixed $actor = null): HrWorkforceScenario
    {
        $scenario = HrWorkforceScenario::query()->create([
            'plan_id' => $plan->getKey(),
            'code' => $data['code'] ?? $this->ctx->nextCode('hr_workforce_scenarios', 'SCN'),
            'name' => $data['name'] ?? 'Skenario '.ucfirst(mb_strtolower((string) ($data['scenario_type'] ?? 'CUSTOM'))),
            'scenario_type' => strtoupper((string) ($data['scenario_type'] ?? 'CUSTOM')),
            'assumptions' => $data['assumptions'] ?? [],
            'inputs' => $data['inputs'] ?? [],
            'status' => 'DRAFT',
            'baseline_scenario_id' => $data['baseline_scenario_id'] ?? null,
            'created_by' => $this->ctx->employeeFor($actor)?->getKey(),
            'notes' => $data['notes'] ?? null,
        ]);

        $this->setAssumptions($scenario, (array) ($data['assumptions'] ?? []), $actor);
        $this->ctx->audit('strategic.scenario.created', HrWorkforceScenario::class, $scenario->getKey(), [
            'plan' => $plan->code, 'type' => $scenario->scenario_type, 'assumptions' => $data['assumptions'] ?? [],
        ], null, $actor);

        return $scenario->refresh();
    }

    /**
     * @param  array<string, mixed>  $assumptions
     */
    public function setAssumptions(HrWorkforceScenario $scenario, array $assumptions, mixed $actor = null): array
    {
        $rows = [];
        foreach ($assumptions as $key => $value) {
            $row = HrWorkforceScenarioAssumption::query()->updateOrCreate(
                ['scenario_id' => $scenario->getKey(), 'key' => (string) $key],
                [
                    'label' => $this->label((string) $key),
                    'value_type' => is_numeric($value) ? 'NUMBER' : (is_bool($value) ? 'BOOLEAN' : 'TEXT'),
                    'value' => is_numeric($value) ? (float) $value : null,
                    'value_json' => is_numeric($value) ? null : ['value' => $value],
                    'source' => $this->sourceOf((string) $key),
                    'is_driver' => in_array($key, $this->driverKeys(), true),
                ]
            );
            $rows[] = $row;
        }

        $scenario->forceFill(['assumptions' => array_merge((array) $scenario->assumptions, $assumptions)])->save();

        return $rows;
    }

    /**
     * Jalankan simulasi: baseline → dampak lever → headcount, biaya,
     * keterampilan, kapasitas, lowongan, perekrutan, attrition, pelatihan.
     *
     * @return array<string, mixed>
     */
    public function simulate(HrWorkforceScenario $scenario, mixed $actor = null): array
    {
        $plan = HrWorkforcePlan::query()->find($scenario->plan_id);
        $assumptions = (array) $scenario->assumptions;

        $employeesBefore = (int) HrEmployee::query()->count();
        $baselineHeadcount = (int) HrEmployee::query()->where('is_active', true)->count();
        $avgCost = $this->planning->averageMonthlyCost();
        $benefitPerEmployee = $this->planning->benefitCostPerEmployee();
        $attritionRate = (float) ($assumptions['attrition_rate'] ?? $this->planning->historicalAttritionRate(12));
        $attritionRate = $attritionRate > 1 ? $attritionRate / 100 : $attritionRate;

        $students = (int) ($assumptions['students_current'] ?? $this->currentStudents());
        $targetStudents = (int) ($assumptions['students_target'] ?? $students);
        if ($deltaPct = (float) ($assumptions['students_delta_percent'] ?? 0.0)) {
            $targetStudents = (int) round($students * (1 + $deltaPct / 100));
        }
        $studentsPerTeacher = (float) ($assumptions['students_per_teacher'] ?? $this->ctx->config('integrations.academic.students_per_teacher_target', 18));

        $teacherDemandNow = $studentsPerTeacher > 0 ? (int) ceil($students / $studentsPerTeacher) : 0;
        $teacherDemandNext = $studentsPerTeacher > 0 ? (int) ceil($targetStudents / $studentsPerTeacher) : 0;

        $adminPer100 = (float) ($assumptions['admin_per_100_students'] ?? 2.5);
        $itPer500 = (float) ($assumptions['it_per_500_students'] ?? 0.5);
        $counselorPer400 = (float) ($assumptions['counselor_per_400_students'] ?? 1.0);
        $clinicPer600 = (float) ($assumptions['clinic_per_600_students'] ?? 1.5);
        $securityPerCampus = (float) ($assumptions['security_per_campus'] ?? 4.0);
        $santriPerMusyrif = (float) ($assumptions['santri_per_musyrif'] ?? $this->ctx->config('integrations.boarding.santri_per_musyrif_target', 25));

        $santri = (int) ($assumptions['santri_current'] ?? $this->currentSantri());
        $santriTarget = (int) ($assumptions['santri_target'] ?? ($assumptions['boarding_delta_percent'] ?? null ? (int) round($santri * (1 + (float) $assumptions['boarding_delta_percent'] / 100)) : $santri));

        $supportDemand = [
            'ADMINISTRATION' => (int) ceil($targetStudents / 100 * $adminPer100) ?: 0,
            'IT' => (int) ceil($targetStudents / 500 * $itPer500),
            'COUNSELOR' => (int) ceil($targetStudents / 400 * $counselorPer400),
            'CLINIC' => (int) ceil($targetStudents / 600 * $clinicPer600),
            'SECURITY' => (int) ceil($securityPerCampus * (float) ($assumptions['campus_count'] ?? 1)),
            'BOARDING' => $santriPerMusyrif > 0 ? (int) ceil($santriTarget / $santriPerMusyrif) : 0,
        ];

        $hires = (int) ($assumptions['hires'] ?? 0);
        $resignations = (int) ($assumptions['resignations'] ?? 0);
        $retirements = (int) ($assumptions['retirements'] ?? 0);
        $hiringFreeze = (bool) ($assumptions['hiring_freeze'] ?? false);
        $automationSaved = (float) ($assumptions['automation_fte_saved'] ?? 0.0);
        $salaryIncreasePct = (float) ($assumptions['salary_increase_percent'] ?? 0.0);
        $benefitDeltaPct = (float) ($assumptions['benefit_delta_percent'] ?? 0.0);

        $teacherGap = $teacherDemandNext - $teacherDemandNow;
        $supportGap = array_sum($supportDemand) - $this->currentSupportHeadcount();
        $netHeadcount = $baselineHeadcount + $hires - $resignations - $retirements - (int) round($automationSaved);
        $requiredHeadcount = $teacherDemandNext + array_sum($supportDemand);

        if ($hiringFreeze) {
            $netHeadcount -= max($hires, 0);
        }

        $monthlyCostPerEmployee = $avgCost * (1 + $salaryIncreasePct / 100);
        $costNow = $this->ctx->money($baselineHeadcount * ($avgCost + $benefitPerEmployee));
        $costNext = $this->ctx->money($netHeadcount * ($monthlyCostPerEmployee + $benefitPerEmployee * (1 + $benefitDeltaPct / 100)));
        $trainingCost = $this->ctx->money(max($teacherGap + $supportGap, 0) * (float) $this->ctx->config('cost.training_per_employee', 750000));
        $recruitmentCost = $this->ctx->money(max($teacherGap + $supportGap, 0) * (float) $this->ctx->config('cost.recruitment_per_hire', 2500000));

        $criticalSkills = $this->skillImpact($assumptions);
        $capacityHoursNow = $this->ctx->money($baselineHeadcount * (float) $this->ctx->config('capacity.weekly_hours', 40) * 4.33);
        $teachingHoursPerMonth = (float) ($assumptions['teaching_hours_per_teacher_month']
            ?? ((float) $this->ctx->config('integrations.academic.default_weekly_teaching_hours', 24) * 4.33));
        $requiredCapacity = $this->ctx->money($teacherDemandNext * $teachingHoursPerMonth);

        $results = [
            'scenario' => [
                'code' => $scenario->code, 'name' => $scenario->name, 'type' => $scenario->scenario_type,
                'assumptions' => $assumptions,
            ],
            'headcount' => [
                'baseline' => $baselineHeadcount,
                'required' => $requiredHeadcount,
                'projected' => $netHeadcount,
                'gap' => $requiredHeadcount - $baselineHeadcount,
                'delta_percent' => $this->ctx->change($baselineHeadcount, $netHeadcount),
            ],
            'demand' => [
                'students_current' => $students, 'students_target' => $targetStudents,
                'students_per_teacher' => $studentsPerTeacher,
                'teachers_current' => $teacherDemandNow, 'teachers_required' => $teacherDemandNext,
                'teacher_gap' => $teacherGap,
                'support_required' => $supportDemand,
                'support_gap' => $supportGap,
            ],
            'cost' => [
                'monthly_now' => $costNow, 'monthly_projected' => $costNext,
                'delta' => $this->ctx->money($costNext - $costNow),
                'delta_percent' => $this->ctx->change($costNow, $costNext),
                'annual_projected' => $this->ctx->money($costNext * 12),
                'training_cost' => $trainingCost, 'recruitment_cost' => $recruitmentCost,
                'budget' => $assumptions['budget_monthly'] ?? null,
                'budget_variance' => isset($assumptions['budget_monthly'])
                    ? $this->ctx->money($costNext - (float) $assumptions['budget_monthly']) : null,
            ],
            'capacity' => [
                'available_hours' => $capacityHoursNow,
                'required_hours' => $requiredCapacity,
                'gap_hours' => $this->ctx->money($requiredCapacity - $capacityHoursNow),
                'utilization_percent' => $this->ctx->percent($requiredCapacity, max($capacityHoursNow, 1)),
            ],
            'skills' => $criticalSkills,
            'movement' => [
                'hires' => $hires, 'resignations' => $resignations, 'retirements' => $retirements,
                'attrition_rate' => round($attritionRate, 4),
                'expected_attrition' => (int) round($netHeadcount * $attritionRate / 12),
                'internal_mobility_potential' => $this->internalMobilityPool(),
                'hiring_freeze' => $hiringFreeze, 'automation_fte_saved' => $automationSaved,
            ],
            'plan' => [
                'hiring_plan' => $hiringFreeze ? [] : array_filter([
                    'TEACHER' => max($teacherGap, 0), 'SUPPORT' => max($supportGap, 0),
                ], fn (int $v): bool => $v > 0),
                'training_plan' => max($teacherGap + $supportGap, 0) > 0 ? ['BUILD at scale: '.($teacherGap + $supportGap).' pegawai'] : [],
                'redeployment_plan' => array_keys(array_filter($supportDemand, fn (int $v): bool => $v < 0)),
            ],
            'risk' => $this->risksFor($requiredHeadcount, $baselineHeadcount, $costNow, $costNext),
            'integrity' => [
                'simulation_only' => true,
                'actual_data_untouched' => $employeesBefore === (int) HrEmployee::query()->count(),
                'note' => 'Simulasi tidak menulis ke employee/posisi/payroll aktual.',
            ],
            'evidence' => [
                'data_source' => 'hr_employees, hr_payroll_items, HRD Phase 2 attraction history',
                'period' => $plan?->period_start?->format('Y-m-d').' → '.$plan?->period_end?->format('Y-m-d'),
                'calculated_at' => now()->toIso8601String(),
            ],
        ];

        $scenario->forceFill([
            'results' => $results,
            'status' => 'SIMULATED',
            'simulated_at' => now(),
        ])->save();

        $this->storeForecasts($scenario, $results);

        $this->ctx->audit('strategic.scenario.simulated', HrWorkforceScenario::class, $scenario->getKey(), [
            'type' => $scenario->scenario_type,
            'headcount_delta' => $results['headcount']['gap'],
            'cost_delta' => $results['cost']['delta'],
        ], null, $actor);

        $this->ctx->event('STRATEGIC_PLANNING', 'ScenarioSimulated', [
            'title' => 'Skenario disimulasikan', 'description' => $scenario->name,
        ], null, HrWorkforceScenario::class, $scenario->getKey(), $actor);

        return $results;
    }

    /**
     * What-if cepat: satu atau banyak lever sekaligus.
     *
     * @param  array<string, mixed>  $levers
     * @return array<string, mixed>
     */
    public function whatIf(HrWorkforcePlan $plan, array $levers, mixed $actor = null): array
    {
        $scenario = $this->createScenario($plan, [
            'name' => 'What-If: '.implode(', ', array_keys($levers)),
            'scenario_type' => 'CUSTOM',
            'assumptions' => $levers,
            'notes' => 'Dibuat dari pertanyaan what-if eksekutif.',
        ], $actor);

        return $this->simulate($scenario, $actor);
    }

    /**
     * Perbandingan berdampingan antar skenario (baseline vs growth vs efisiensi).
     *
     * @param  array<int, int>  $scenarioIds
     * @return array<string, mixed>
     */
    public function compare(array $scenarioIds): array
    {
        $scenarios = HrWorkforceScenario::query()->whereIn('id', $scenarioIds)->get();
        $rows = [];
        foreach ($scenarios as $scenario) {
            $results = (array) $scenario->results;
            $rows[] = [
                'code' => $scenario->code,
                'name' => $scenario->name,
                'type' => $scenario->scenario_type,
                'headcount' => $results['headcount']['projected'] ?? null,
                'headcount_gap' => $results['headcount']['gap'] ?? null,
                'cost_monthly' => $results['cost']['monthly_projected'] ?? null,
                'cost_delta' => $results['cost']['delta'] ?? null,
                'capacity_gap_hours' => $results['capacity']['gap_hours'] ?? null,
                'attrition' => $results['movement']['expected_attrition'] ?? null,
                'hiring' => $results['plan']['hiring_plan'] ?? [],
                'training_need' => $results['demand']['teacher_gap'] ?? null,
                'risk' => $results['risk'] ?? [],
            ];
        }

        return [
            'scenarios' => $rows,
            'lowest_cost' => collect($rows)->sortBy('cost_monthly')->first()['code'] ?? null,
            'highest_capacity' => collect($rows)->sortByDesc('headcount')->first()['code'] ?? null,
            'note' => 'Perbandingan hanya menyajikan trade-off; keputusan tetap di manusia.',
        ];
    }

    public function adopt(HrWorkforceScenario $scenario, mixed $actor = null, ?string $decision = null): HrWorkforceScenario
    {
        $scenario->forceFill([
            'status' => 'ADOPTED',
            'comparison' => array_merge((array) $scenario->comparison, [
                'adopted_at' => now()->toIso8601String(),
                'decision' => $decision,
                'note' => 'Adopsi mencatat keputusan. Perubahan aktual tetap lewat workflow Phase 1/2.',
            ]),
        ])->save();

        $this->ctx->audit('strategic.scenario.adopted', HrWorkforceScenario::class, $scenario->getKey(), [
            'decision' => $decision,
        ], $decision, $actor);

        return $scenario->refresh();
    }

    /* ------------------------------------------------------------------ */

    private function storeForecasts(HrWorkforceScenario $scenario, array $results): void
    {
        $plan = HrWorkforcePlan::query()->find($scenario->plan_id);
        if (! $plan) {
            return;
        }

        $periods = $this->ctx->periodsBetween($plan->period_start ?? now()->startOfYear(), $plan->period_end ?? now()->endOfYear(), 36);
        $headcount = (float) ($results['headcount']['projected'] ?? 0);
        $totalMonths = max(count($periods), 1);

        foreach ($periods as $index => $period) {
            $progress = ($index + 1) / $totalMonths;
            $baseline = (float) ($results['headcount']['baseline'] ?? 0);
            $value = round($baseline + (($headcount - $baseline) * $progress), 2);

            HrWorkforceForecast::query()->updateOrCreate(
                ['plan_id' => $plan->getKey(), 'scenario_id' => $scenario->getKey(), 'forecast_type' => 'HEADCOUNT', 'period' => $period],
                [
                    'organization_id' => $plan->organization_id,
                    'horizon_months' => $totalMonths,
                    'value' => $value,
                    'lower_bound' => round($value * 0.95, 2),
                    'upper_bound' => round($value * 1.05, 2),
                    'confidence' => 0.7,
                    'method' => 'SIMULATION',
                    'inputs' => $results['scenario']['assumptions'] ?? [],
                    'generated_at' => now(),
                ]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function skillImpact(array $assumptions): array
    {
        $rows = $this->ctx->safe(function (): array {
            if (! $this->ctx->hasTable('hr_skill_forecasts')) {
                return [];
            }

            return DB::table('hr_skill_forecasts')->orderByDesc('gap')->limit(5)
                ->get(['skill_code', 'current_supply', 'future_demand', 'gap'])
                ->map(fn ($row): array => [
                    'skill_code' => $row->skill_code,
                    'current_supply' => (float) $row->current_supply,
                    'future_demand' => (float) $row->future_demand,
                    'gap' => (float) $row->gap,
                ])->all();
        }, []);

        return [
            'critical_skill_focus' => $assumptions['critical_skills'] ?? array_column($rows, 'skill_code'),
            'gap_snapshot' => $rows,
            'note' => 'Kebutuhan keterampilan mengikuti permintaan tenaga kerja, bukan asumsi kosong.',
        ];
    }

    private function risksFor(int $required, int $baseline, float $costNow, float $costNext): array
    {
        $risks = [];
        $gap = $required - $baseline;
        if ($gap > 0) {
            $risks[] = ['risk' => 'WORKFORCE_SHORTAGE', 'severity' => $gap > $baseline * 0.2 ? 'HIGH' : 'MEDIUM', 'value' => $gap];
        }
        if ($costNext > $costNow) {
            $deltaPct = $this->ctx->change($costNow, $costNext);
            $risks[] = ['risk' => 'COST_PRESSURE', 'severity' => $deltaPct > 25 ? 'HIGH' : 'MEDIUM', 'value' => $deltaPct];
        }

        return $risks;
    }

    private function internalMobilityPool(): int
    {
        return (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_talent_pool_members')->where('status', 'ACTIVE')->count(), 0);
    }

    private function currentStudents(): int
    {
        foreach (['students', 'ac_students', 'student_profiles'] as $table) {
            if ($this->ctx->hasTable($table)) {
                return $this->ctx->probeCount($table, ['status' => 'ACTIVE'], 0) ?: $this->ctx->probeCount($table, [], 0);
            }
        }

        return (int) $this->ctx->config('integrations.academic.fallback_students', 0);
    }

    private function currentSantri(): int
    {
        foreach (['boarding_residents', 'boarding_students', 'santri'] as $table) {
            if ($this->ctx->hasTable($table)) {
                return $this->ctx->probeCount($table, [], 0);
            }
        }

        return (int) $this->ctx->config('integrations.boarding.fallback_santri', 0);
    }

    private function currentSupportHeadcount(): int
    {
        return (int) $this->ctx->safe(function (): int {
            return (int) HrEmployee::query()->where('is_active', true)
                ->whereRaw("lower(worker_type) in ('staff','administration','administrasi','it','support','counselor','clinic','security','boarding')")
                ->count();
        }, 0);
    }

    /**
     * @return array<int, string>
     */
    private function driverKeys(): array
    {
        return [
            'students_delta_percent', 'students_target', 'santri_target', 'boarding_delta_percent',
            'hires', 'resignations', 'retirements', 'hiring_freeze', 'salary_increase_percent',
            'automation_fte_saved', 'campus_count', 'budget_monthly', 'clinic_visit_delta_percent',
        ];
    }

    private function sourceOf(string $key): string
    {
        return match (true) {
            str_contains($key, 'student'), str_contains($key, 'campus'), str_contains($key, 'santri') => 'ACADEMIC',
            str_contains($key, 'salary'), str_contains($key, 'cost'), str_contains($key, 'budget') => 'FINANCE',
            default => 'EXECUTIVE',
        };
    }

    private function label(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }
}
