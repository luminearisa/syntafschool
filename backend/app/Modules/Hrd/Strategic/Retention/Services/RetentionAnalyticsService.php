<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Retention\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\People\Models\HrAttritionSnapshot;
use App\Modules\Hrd\Strategic\People\Models\HrRetentionSignal;
use App\Modules\Hrd\Strategic\Support\PeopleFacts;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retention & Attrition Intelligence (§41, §42).
 *
 * Sistem tidak pernah menyatakan seseorang "pasti akan resign". Yang disajikan
 * adalah SINYAL (Low/Medium/High) beserta faktor pembentuknya dan buktinya.
 */
class RetentionAnalyticsService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly PeopleFacts $facts,
    ) {}

    /**
     * Hitung sinyal retensi untuk populasi pegawai aktif.
     *
     * @return array<string, mixed>
     */
    public function signals(?int $organizationId = null, int $limit = 200, mixed $user = null): array
    {
        $employees = $this->ctx->scopedEmployees($user, $organizationId)->where('is_active', true)
            ->limit($limit)->get(['id', 'employee_number', 'position_id', 'department_id', 'manager_employee_id', 'hire_date']);
        $payPercentiles = $this->payPercentiles();
        $rows = [];
        $levels = ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0];

        foreach ($employees as $employee) {
            $employeeId = (int) $employee->getKey();
            $factors = [];
            $score = 0.0;

            // 1. Masa kerja panjang tanpa promosi
            $monthsSincePromotion = $this->facts->monthsSincePromotion($employeeId);
            if ($monthsSincePromotion !== null && $monthsSincePromotion > 48) {
                $score += 15;
                $factors[] = ['factor' => 'NO_PROMOTION', 'value_months' => $monthsSincePromotion, 'weight' => 15];
            }
            // 2. Kompensasi di bawah persentil 25
            $pay = $this->facts->monthlyPay($employeeId);
            $percentile = $pay !== null ? $this->percentileOf($pay, $payPercentiles) : null;
            if ($percentile !== null && $percentile < 25) {
                $score += 20;
                $factors[] = ['factor' => 'BELOW_P25_COMPENSATION', 'percentile' => $percentile, 'weight' => 20];
            }
            // 3. Kinerja tinggi tanpa pengembangan
            $performance = $this->facts->performanceScore($employeeId);
            $learning = $this->facts->learningParticipation($employeeId);
            if (($performance ?? 0) >= 4 && $learning < 50) {
                $score += 15;
                $factors[] = ['factor' => 'HIGH_PERFORMER_LOW_DEVELOPMENT', 'performance' => $performance, 'learning' => $learning, 'weight' => 15];
            }
            // 4. Ketidakhadiran tidak teratur
            $irregularities = $this->facts->attendanceIrregularities($employeeId);
            if ($irregularities >= 3) {
                $score += 15;
                $factors[] = ['factor' => 'ATTENDANCE_IRREGULARITY', 'count' => $irregularities, 'weight' => 15];
            }
            // 5. Pasar kerja internal: memiliki keterampilan langka (mudah di-rekrut orang lain)
            $criticalHeld = $this->ctx->safe(fn (): int => (int) DB::table('hr_employee_skills as es')
                ->join('hr_skills as s', 's.id', '=', 'es.skill_id')
                ->join('hr_skill_demand as d', 'd.skill_code', '=', 's.code')
                ->where('es.employee_id', $employeeId)
                ->whereIn('d.criticality', ['CRITICAL', 'SCARCE', 'BUSINESS_CRITICAL', 'REGULATORY'])
                ->distinct()->count('s.code'), 0);
            if ($criticalHeld > 0) {
                $score += 10;
                $factors[] = ['factor' => 'SCARCE_SKILL_HOLDER', 'skills' => $criticalHeld, 'weight' => 10];
            }
            // 6. Pergantian atasan baru-baru ini
            $managerChange = $this->ctx->safe(fn (): bool => $this->ctx->hasTable('hr_transactions') && DB::table('hr_transactions')
                ->where('employee_id', $employeeId)->where('transaction_type', 'like', '%MANAGER%')
                ->where('created_at', '>=', now()->subYear())->exists(), false);
            if ($managerChange) {
                $score += 10;
                $factors[] = ['factor' => 'RECENT_MANAGER_CHANGE', 'weight' => 10];
            }
            // 7. Tidak ada mobilitas internal
            $mobility = $this->ctx->safe(fn (): int => (int) DB::table('hr_internal_job_applications')
                ->where('employee_id', $employeeId)->where('created_at', '>=', now()->subYear())->count(), 0);
            if ($mobility === 0 && ($monthsSincePromotion ?? 0) > 36) {
                $score += 10;
                $factors[] = ['factor' => 'NO_INTERNAL_MOBILITY', 'weight' => 10];
            }

            $level = $score >= 50 ? 'HIGH' : ($score >= 25 ? 'MEDIUM' : 'LOW');
            $levels[$level]++;
            $tenure = $this->facts->tenureMonths($employeeId);

            $signal = HrRetentionSignal::query()->updateOrCreate(
                ['employee_id' => $employeeId, 'period' => now()->format('Y-m')],
                [
                    'signal_level' => $level, 'retention_score' => $this->ctx->score($score),
                    'factors' => $factors, 'contributing_signals' => array_column($factors, 'factor'),
                    'evidence' => ['pay_percentile' => $percentile, 'performance' => $performance, 'learning' => $learning,
                        'irregularities' => $irregularities],
                    'period' => now()->format('Y-m'), 'tenure_months' => $tenure,
                    'manager_change_recent' => $managerChange, 'promotion_gap_months' => $monthsSincePromotion,
                    'compensation_percentile' => $percentile, 'learning_participation' => $learning,
                    'attendance_irregularities' => $irregularities,
                    'career_progress_note' => $monthsSincePromotion === null ? 'Riwayat promosi belum tersedia' : null,
                    'detected_at' => now(), 'status' => 'ACTIVE',
                ]
            );

            $rows[] = [
                'employee_id' => $employeeId, 'employee_number' => $employee->employee_number,
                'signal_level' => $level, 'score' => $this->ctx->score($score),
                'factors' => $factors, 'retention_signal_id' => $signal->getKey(),
                'disclaimer' => 'Sinyal indikatif berbasis data; bukan prediksi pasti dan bukan dasar tindakan otomatis.',
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $this->ctx->audit('strategic.retention_signals.generated', HrRetentionSignal::class, null, [
            'population' => count($rows), 'distribution' => $levels,
        ], null, $user);

        return ['population' => count($rows), 'distribution' => $levels, 'rows' => $rows];
    }

    /**
     * Analisis attrition lintas dimensi (§42).
     *
     * @return array<string, mixed>
     */
    public function attritionAnalytics(?int $organizationId = null, ?string $period = null, string $dimension = 'DEPARTMENT'): array
    {
        $period = $period ?? now()->format('Y');
        $column = match (strtoupper($dimension)) {
            'POSITION' => 'position_id', 'LOCATION' => 'location_id', 'EMPLOYMENT_TYPE' => 'employment_type',
            'MANAGER' => 'manager_employee_id', 'TENURE' => 'hire_date', default => 'department_id',
        };
        $start = Carbon::parse($period.'-01-01');
        $end = $start->copy()->endOfYear();

        $headcountStart = (int) HrEmployee::query()->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->where('hire_date', '<=', $start->toDateString())
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>', $start->toDateString()))
            ->count();
        $headcountEnd = (int) HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->count();

        $leavers = HrEmployee::query()->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereNotNull('termination_date')
            ->whereBetween('termination_date', [$start->toDateString(), $end->toDateString()])
            ->get(['id', $column, 'termination_date', 'exit_reason_code']);

        $byDimension = $leavers->groupBy(fn (HrEmployee $row) => (string) ($row->{$column} ?? 'UNKNOWN'))
            ->map(function ($group, $key) use ($headcountEnd): array {
                $count = $group->count();

                return [
                    'key' => $key, 'leavers' => $count,
                    'turnover_rate' => $this->ctx->percent($count, max($headcountEnd, 1)),
                    'voluntary' => $group->filter(fn (HrEmployee $row): bool => ! str_contains(mb_strtolower((string) $row->exit_reason_code), 'terminat'))->count(),
                    'involuntary' => $group->filter(fn (HrEmployee $row): bool => str_contains(mb_strtolower((string) $row->exit_reason_code), 'terminat'))->count(),
                ];
            })->values()->sortByDesc('leavers')->values()->all();

        $snapshot = HrAttritionSnapshot::query()->updateOrCreate(
            ['period' => $period, 'granularity' => 'ANNUAL', 'dimension' => strtoupper($dimension), 'dimension_key' => 'ALL'],
            [
                'headcount_start' => $headcountStart, 'headcount_end' => $headcountEnd, 'leavers' => $leavers->count(),
                'turnover_rate' => $headcountStart > 0 ? round($leavers->count() / $headcountStart, 4) : 0.0,
                'avg_tenure_months' => $this->ctx->score((float) ($leavers->avg(fn (HrEmployee $row): float => $row->hire_date
                    ? (float) $this->ctx->monthsBetween($row->hire_date->toDateString(), (string) $row->termination_date) : 0.0) ?? 0.0)),
                'voluntary' => $leavers->filter(fn (HrEmployee $row): bool => $row->exit_reason_code === null
                    || ! str_contains(mb_strtolower((string) $row->exit_reason_code), 'terminat'))->count(),
                'involuntary' => $leavers->filter(fn (HrEmployee $row): bool => str_contains(mb_strtolower((string) $row->exit_reason_code), 'terminat'))->count(),
                'trend_ref' => ['by_dimension' => $byDimension],
                'generated_at' => now(),
            ]
        );

        return [
            'period' => $period, 'dimension' => strtoupper($dimension),
            'headcount_start' => $headcountStart, 'headcount_end' => $headcountEnd,
            'leavers' => $leavers->count(),
            'turnover_rate_percent' => $headcountStart > 0 ? $this->ctx->percent($leavers->count(), $headcountStart) : 0.0,
            'by_dimension' => $byDimension, 'snapshot_id' => $snapshot->getKey(),
            'note' => 'Turnover dihitung dari data pegawai keluar yang tercatat.',
        ];
    }

    /**
     * Deret turnover bulanan + tren.
     *
     * @return array<string, mixed>
     */
    public function turnoverSeries(int $months = 12, ?int $organizationId = null): array
    {
        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $period = Carbon::now()->subMonths($i);
            $leavers = (int) $this->ctx->safe(fn (): int => (int) HrEmployee::query()
                ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
                ->whereNotNull('termination_date')
                ->whereBetween('termination_date', [$period->copy()->startOfMonth()->toDateString(), $period->copy()->endOfMonth()->toDateString()])
                ->count(), 0);
            $headcount = (int) $this->ctx->safe(fn (): int => (int) HrEmployee::query()
                ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
                ->where('hire_date', '<=', $period->copy()->endOfMonth()->toDateString())
                ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>', $period->copy()->endOfMonth()->toDateString()))
                ->count(), 0);
            $series[] = [
                'period' => $period->format('Y-m'), 'leavers' => $leavers, 'headcount' => $headcount,
                'rate' => $headcount > 0 ? $this->ctx->score(($leavers / $headcount) * 100) : 0.0,
            ];
        }

        return ['series' => $series, 'trend' => $this->ctx->trend(array_column($series, 'rate'))];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?int $organizationId = null): array
    {
        $signals = $this->signals($organizationId, 200);
        $attrition = $this->attritionAnalytics($organizationId);

        return [
            'signals' => ['population' => $signals['population'], 'distribution' => $signals['distribution'],
                'high_risk' => array_slice(array_values(array_filter($signals['rows'], fn (array $row): bool => $row['signal_level'] === 'HIGH')), 0, 10)],
            'attrition' => $attrition,
            'turnover_trend' => $this->turnoverSeries(12, $organizationId)['trend'],
            'note' => 'Sinyal retensi dipakai untuk percakapan dan intervensi, bukan keputusan otomatis.',
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, float>
     */
    private function payPercentiles(): array
    {
        return $this->ctx->safe(function (): array {
            if (! $this->ctx->hasTable('hr_payroll_items')) {
                return [];
            }

            return DB::table('hr_payroll_items')->orderBy('net_amount')->limit(2000)->pluck('net_amount')
                ->map(fn ($value): float => (float) $value)->all();
        }, []);
    }

    /**
     * @param  array<int, float>  $values
     */
    private function percentileOf(float $value, array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        $below = count(array_filter($values, fn (float $row): bool => $row <= $value));

        return $this->ctx->score(($below / count($values)) * 100);
    }
}
