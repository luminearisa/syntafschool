<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Compensation\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\People\Models\HrCompensationScenario;
use App\Modules\Hrd\Strategic\People\Models\HrIncentiveSimulation;
use App\Modules\Hrd\Strategic\People\Models\HrPayEquityAnalysis;
use App\Modules\Hrd\Strategic\People\Models\HrPayEquityFinding;
use App\Modules\Hrd\Strategic\Support\PeopleFacts;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use Illuminate\Support\Facades\DB;

/**
 * Compensation Intelligence (§56–§59): distribusi upah, analisis ekuitas
 * (sebagai SINYAL anomali, bukan tuduhan diskriminasi), perencanaan kenaikan,
 * dan simulasi bonus. Tidak memposting ke payroll — itu tetap hak Phase 2.
 */
class CompensationIntelligenceService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly PeopleFacts $facts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analytics(?int $organizationId = null, int $limit = 500, mixed $user = null): array
    {
        $employees = $this->ctx->scopedEmployees($user, $organizationId)->where('is_active', true)->limit($limit)->get(['id', 'department_id', 'position_id', 'grade_code']);
        $employeeIds = $employees->pluck('id')->all();
        $pay = $this->payByEmployee($employeeIds);

        $values = array_values($pay);
        $byDepartment = [];
        foreach ($employees as $employee) {
            $departmentId = $employee->department_id ?? 0;
            if (isset($pay[$employee->getKey()])) {
                $byDepartment[$departmentId][] = $pay[$employee->getKey()];
            }
        }

        $distribution = [];
        foreach ($byDepartment as $departmentId => $amounts) {
            $distribution[] = [
                'department_id' => $departmentId, 'employees' => count($amounts),
                'median' => $this->ctx->median($amounts),
                'p25' => $this->ctx->percentile($amounts, 25), 'p75' => $this->ctx->percentile($amounts, 75),
                'min' => round(min($amounts), 2), 'max' => round(max($amounts), 2),
            ];
        }

        usort($distribution, fn (array $a, array $b): int => $b['median'] <=> $a['median']);

        return [
            'employees_with_pay' => count($values),
            'median' => $this->ctx->median($values),
            'p25' => $this->ctx->percentile($values, 25),
            'p75' => $this->ctx->percentile($values, 75),
            'p90' => $this->ctx->percentile($values, 90),
            'range_ratio' => $values !== [] && min($values) > 0 ? round(max($values) / min($values), 2) : null,
            'total_monthly' => $this->ctx->money(array_sum($values)),
            'by_department' => $distribution,
            'pay_compression' => $this->compression($employees, $pay),
            'note' => 'Distribusi memakai payroll terakhir yang tersedia per pegawai.',
        ];
    }

    /**
     * Analisis ekuitas upah (§57). Output: POTENTIAL ANOMALY + bukti + grup
     * pembanding — bukan pernyataan diskriminasi.
     *
     * @return array<string, mixed>
     */
    public function payEquity(?int $organizationId = null, array $options = [], mixed $actor = null): array
    {
        $tolerance = (float) ($options['tolerance_percent'] ?? 25);
        $analysis = HrPayEquityAnalysis::query()->create([
            'code' => $options['code'] ?? $this->ctx->nextCode('hr_pay_equity_analyses', 'PEQ'),
            'name' => $options['name'] ?? 'Analisis ekuitas upah '.now()->format('Y-m'),
            'scope_organization_id' => $organizationId,
            'comparison_group' => $options['comparison_group'] ?? ['grade_code', 'position_id'],
            'period' => $options['period'] ?? now()->format('Y-m'),
            'method' => 'REGRESSION_BAND',
            'status' => 'COMPLETED',
            'reviewed_by' => $this->ctx->employeeFor($actor)?->getKey(),
            'reviewed_at' => now(),
        ]);

        $employees = HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->limit(500)->get(['id', 'position_id', 'grade_code', 'department_id']);
        $pay = $this->payByEmployee($employees->pluck('id')->all());

        $groups = [];
        foreach ($employees as $employee) {
            if (! isset($pay[$employee->getKey()])) {
                continue;
            }
            $key = ($employee->grade_code ?? '-').'|'.($employee->position_id ?? '-');
            $groups[$key][] = ['employee_id' => $employee->getKey(), 'amount' => $pay[$employee->getKey()]];
        }

        $findings = [];
        foreach ($groups as $key => $members) {
            if (count($members) < 2) {
                continue;
            }
            $amounts = array_column($members, 'amount');
            $median = $this->ctx->median($amounts);
            $low = $median * (1 - $tolerance / 100);
            $high = $median * (1 + $tolerance / 100);

            foreach ($members as $member) {
                if ($member['amount'] >= $low && $member['amount'] <= $high) {
                    continue;
                }
                $variance = $median > 0 ? round((($member['amount'] - $median) / $median) * 100, 2) : 0.0;
                $findings[] = HrPayEquityFinding::query()->create([
                    'analysis_id' => $analysis->getKey(), 'employee_id' => $member['employee_id'],
                    'anomaly_type' => $variance > 0 ? 'ABOVE_RANGE' : 'BELOW_RANGE',
                    'position_id' => null, 'grade_code' => explode('|', (string) $key)[0],
                    'comparison_group' => 'grade+position: '.$key,
                    'expected_min' => $this->ctx->money($low), 'expected_max' => $this->ctx->money($high),
                    'actual_amount' => $this->ctx->money($member['amount']), 'variance_percent' => $variance,
                    'evidence' => ['group_size' => count($members), 'group_median' => $median, 'tolerance_percent' => $tolerance],
                    'status' => 'POTENTIAL_ANOMALY',
                ]);
            }
        }

        $analysis->forceFill([
            'anomaly_count' => count($findings),
            'findings' => collect($findings)->map(fn (HrPayEquityFinding $row): array => [
                'employee_id' => (int) $row->employee_id, 'type' => $row->anomaly_type,
                'variance_percent' => (float) $row->variance_percent,
            ])->all(),
            'result_summary' => [
                'groups_compared' => count($groups), 'tolerance_percent' => $tolerance,
                'note' => 'Anomali adalah sinyal untuk ditinjau dengan konteks (pengalaman, kinerja, masa kerja) — bukan kesimpulan diskriminasi.',
            ],
        ])->save();

        $this->ctx->audit('strategic.pay_equity.analysed', HrPayEquityAnalysis::class, $analysis->getKey(), [
            'anomalies' => count($findings), 'groups' => count($groups),
        ], null, $actor);

        return [
            'analysis' => $analysis->code,
            'groups_compared' => count($groups),
            'findings' => collect($findings)->map(fn (HrPayEquityFinding $row): array => [
                'employee_id' => (int) $row->employee_id, 'anomaly_type' => $row->anomaly_type,
                'actual' => (float) $row->actual_amount, 'expected_range' => [(float) $row->expected_min, (float) $row->expected_max],
                'variance_percent' => (float) $row->variance_percent, 'evidence' => $row->evidence,
                'status' => $row->status,
            ])->all(),
            'caveat' => 'Perbedaan dapat dijelaskan oleh pengalaman, kinerja, masa kerja, atau kesepakatan khusus. Selalu periksa bukti sebelum menyimpulkan.',
        ];
    }

    /**
     * Simulasi kenaikan gaji / promosi / penyesuaian (§58).
     *
     * @return array<string, mixed>
     */
    public function simulate(array $data, mixed $actor = null): HrCompensationScenario
    {
        $percent = (float) ($data['increase_percent'] ?? 5.0);
        $method = strtoupper((string) ($data['distribution_method'] ?? 'EQUAL'));
        $budget = (float) ($data['budget_amount'] ?? 0);
        $scope = (array) ($data['scope'] ?? []);
        $employees = HrEmployee::query()->where('is_active', true)
            ->when($scope['organization_id'] ?? null, fn ($q, $org) => $q->where('organization_id', $org))
            ->when($scope['department_id'] ?? null, fn ($q, $dept) => $q->where('department_id', $dept))
            ->limit(500)->get(['id', 'department_id']);

        $pay = $this->payByEmployee($employees->pluck('id')->all());
        $impact = [];
        $total = 0.0;
        $byDepartment = [];
        $above = $below = 0;

        foreach ($employees as $employee) {
            $current = $pay[$employee->getKey()] ?? null;
            if ($current === null) {
                continue;
            }
            $factor = match ($method) {
                'PERFORMANCE_BASED' => max(0.5, min(1.5, (($this->facts->performanceScore((int) $employee->getKey()) ?? 3.0) / 3.0))),
                'GRADE_BASED' => 1.2,
                default => 1.0,
            };
            $increase = $current * ($percent / 100) * $factor;
            $total += $increase;
            $byDepartment[$employee->department_id ?? 0] = ($byDepartment[$employee->department_id ?? 0] ?? 0) + $increase;
            if ($increase > $current * ($percent / 100)) {
                $above++;
            } elseif ($increase < $current * ($percent / 100)) {
                $below++;
            }
            $impact[] = ['employee_id' => (int) $employee->getKey(), 'current' => $current, 'increase' => $this->ctx->money($increase)];
        }

        $scenario = HrCompensationScenario::query()->create([
            'code' => $data['code'] ?? $this->ctx->nextCode('hr_compensation_scenarios', 'CMP'),
            'name' => $data['name'] ?? ('Kenaikan '.$percent.'% ('.$method.')'),
            'scenario_type' => strtoupper((string) ($data['scenario_type'] ?? 'SALARY_INCREASE')),
            'increase_percent' => $percent, 'distribution_method' => $method, 'scope' => $scope,
            'population_count' => count($impact), 'budget_amount' => $budget > 0 ? $budget : null,
            'projected_total_cost' => $this->ctx->money($total),
            'budget_variance' => $budget > 0 ? $this->ctx->money($total - $budget) : null,
            'by_department' => $byDepartment, 'employee_impact' => [
                'count' => count($impact), 'above_average_increase' => $above, 'below_average_increase' => $below,
                'average_increase' => count($impact) > 0 ? $this->ctx->money($total / count($impact)) : 0.0,
                'sample' => array_slice($impact, 0, 20),
            ],
            'status' => 'SIMULATED', 'created_by' => $this->ctx->employeeFor($actor)?->getKey(),
            'simulated_at' => now(),
        ]);

        $this->ctx->audit('strategic.compensation.simulated', HrCompensationScenario::class, $scenario->getKey(), [
            'percent' => $percent, 'method' => $method, 'population' => count($impact),
            'projected_cost' => $total, 'budget_variance' => $scenario->budget_variance,
        ], null, $actor);

        return $scenario->refresh();
    }

    /**
     * Simulasi bonus/insentif (§59).
     *
     * @return array<string, mixed>
     */
    public function simulateIncentive(array $data, mixed $actor = null): HrIncentiveSimulation
    {
        $basis = (array) ($data['basis'] ?? ['performance' => 0.7, 'goal' => 0.3]);
        $budget = (float) ($data['budget_amount'] ?? 0);
        $poolPercent = (float) ($data['pool_percent'] ?? 5.0);
        $employees = HrEmployee::query()->where('is_active', true)->limit(500)->pluck('id');

        $rows = [];
        $total = 0.0;
        foreach ($employees as $employeeId) {
            $performance = $this->facts->performanceScore((int) $employeeId) ?? 3.0;
            $pay = $this->facts->monthlyPay((int) $employeeId) ?? 0.0;
            $weighted = (($performance / 5) * ($basis['performance'] ?? 0.7)) + (0.5 * ($basis['goal'] ?? 0.3));
            $incentive = $pay * ($poolPercent / 100) * $weighted;
            if ($incentive <= 0) {
                continue;
            }
            $total += $incentive;
            $rows[] = ['employee_id' => (int) $employeeId, 'performance' => $performance, 'incentive' => $this->ctx->money($incentive)];
        }

        usort($rows, fn (array $a, array $b): int => $b['incentive'] <=> $a['incentive']);

        $simulation = HrIncentiveSimulation::query()->create([
            'code' => $data['code'] ?? $this->ctx->nextCode('hr_incentive_simulations', 'INC'),
            'name' => $data['name'] ?? 'Simulasi bonus '.now()->format('Y'),
            'period' => $data['period'] ?? now()->format('Y'),
            'basis' => $basis + ['pool_percent' => $poolPercent],
            'eligible_count' => count($rows), 'total_cost' => $this->ctx->money($total),
            'budget_amount' => $budget > 0 ? $budget : null,
            'budget_variance' => $budget > 0 ? $this->ctx->money($total - $budget) : null,
            'distribution' => ['average' => count($rows) > 0 ? $this->ctx->money($total / count($rows)) : 0.0, 'median' => $this->ctx->median(array_column($rows, 'incentive'))],
            'top_recipients' => array_slice($rows, 0, 10), 'status' => 'SIMULATED',
            'created_by' => $this->ctx->employeeFor($actor)?->getKey(), 'simulated_at' => now(),
        ]);

        $this->ctx->audit('strategic.incentive.simulated', HrIncentiveSimulation::class, $simulation->getKey(), [
            'eligible' => count($rows), 'total_cost' => $total, 'budget' => $budget,
        ], null, $actor);

        return $simulation->refresh();
    }

    /**
     * Varians terhadap anggaran (integrasi Finance, §86).
     *
     * @return array<string, mixed>
     */
    public function budgetVariance(?int $organizationId = null, ?string $period = null): array
    {
        $period = $period ?? now()->format('Y-m');
        $actual = (float) $this->ctx->safe(fn (): float => $this->ctx->hasTable('hr_payroll_items')
            ? (float) DB::table('hr_payroll_items')->where('created_at', 'like', $period.'%')->sum('net_amount') : 0.0, 0.0);
        $projected = (float) $this->ctx->safe(fn (): float => DB::table('hr_workforce_cost_forecasts')
            ->where('period', $period)->when($organizationId, fn ($q, $org) => $q->where('organization_id', $org))->sum('total_amount'), 0.0);
        $budget = (float) $this->ctx->safe(fn (): float => DB::table('hr_workforce_cost_forecasts')
            ->where('period', $period)->whereNotNull('budget_amount')->when($organizationId, fn ($q, $org) => $q->where('organization_id', $org))->sum('budget_amount'), 0.0);

        return [
            'period' => $period, 'actual' => $this->ctx->money($actual),
            'projected' => $this->ctx->money($projected), 'budget' => $budget > 0 ? $this->ctx->money($budget) : null,
            'actual_vs_projected' => $this->ctx->money($actual - $projected),
            'variance_to_budget' => $budget > 0 ? $this->ctx->money($projected - $budget) : null,
            'integration' => $budget > 0 ? 'BUDGET_TERSEDIA' : 'FINANCE_BELUM_TERPASANG',
        ];
    }

    /**
     * @return array<int, float>
     */
    private function payByEmployee(array $employeeIds): array
    {
        return $this->ctx->safe(function () use ($employeeIds): array {
            if ($employeeIds === [] || ! $this->ctx->hasTable('hr_payroll_items')) {
                return [];
            }

            return DB::table('hr_payroll_items')->whereIn('employee_id', $employeeIds)
                ->selectRaw('employee_id, max(net_amount) as net_amount')->groupBy('employee_id')
                ->pluck('net_amount', 'employee_id')->map(fn ($value): float => (float) $value)->all();
        }, []);
    }

    /**
     * @param  array<int, float>  $pay
     * @return array<int, array<string, mixed>>
     */
    private function compression($employees, array $pay): array
    {
        $positions = [];
        foreach ($employees as $employee) {
            if (! isset($pay[$employee->getKey()]) || ! $employee->position_id) {
                continue;
            }
            $positions[$employee->position_id][] = $pay[$employee->getKey()];
        }

        $rows = [];
        foreach ($positions as $positionId => $amounts) {
            if (count($amounts) < 2) {
                continue;
            }
            $min = min($amounts);
            $max = max($amounts);
            $ratio = $min > 0 ? round($max / $min, 2) : null;
            $rows[] = [
                'position_id' => $positionId, 'min' => round($min, 2), 'max' => round($max, 2), 'ratio' => $ratio,
                'flag' => $ratio !== null && $ratio > 2.5 ? 'POTENTIAL_COMPRESSION' : 'NORMAL',
                'holders' => count($amounts),
            ];
        }

        return $rows;
    }
}
