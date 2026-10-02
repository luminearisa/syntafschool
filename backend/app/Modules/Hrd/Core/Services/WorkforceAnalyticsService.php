<?php

namespace App\Modules\Hrd\Core\Services;

use App\Modules\Hrd\Core\Models\HrAnalyticsSnapshot;
use App\Modules\Hrd\Learning\Services\LearningService;
use App\Modules\Hrd\Leave\Models\HrLeaveBalance;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Payroll\Models\HrPayrollRun;
use App\Modules\Hrd\Performance\Services\PerformanceService;
use App\Modules\Hrd\Talent\Services\TalentService;
use App\Modules\Hrd\Workforce\Models\HrAttendanceRecord;
use App\Modules\Hrd\Workforce\Models\HrOvertimeRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Analitik tenaga kerja (§83–§88).
 *
 * Semua angka dihitung saat diminta dan disimpan sebagai snapshot harian
 * sehingga tren dapat dibaca tanpa menghitung ulang setahun data. Bila suatu
 * modul belum dipakai sekolah, angkanya nol — bukan galat.
 */
class WorkforceAnalyticsService
{
    public function __construct(
        private readonly PerformanceService $performance,
        private readonly LearningService $learning,
        private readonly TalentService $talent,
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(?int $organizationId = null, ?string $from = null, ?string $to = null): array
    {
        $from = $from ?? now()->startOfYear()->toDateString();
        $to = $to ?? now()->toDateString();

        return [
            'headcount' => $this->headcount($organizationId),
            'turnover' => $this->turnover($from, $to, $organizationId),
            'attendance' => $this->attendance($from, $to, $organizationId),
            'leave' => $this->leave($from, $to, $organizationId),
            'overtime' => $this->overtime($from, $to, $organizationId),
            'payroll' => $this->payroll($organizationId),
            'performance' => $this->performance->summary(null, $organizationId),
            'learning' => $this->learning->analytics($organizationId),
            'talent' => $this->talent->dashboard($organizationId),
            'workforce_cost' => $this->workforceCost($from, $to, $organizationId),
        ];
    }

    /** @return array<string, mixed> */
    public function headcount(?int $organizationId = null): array
    {
        $query = HrEmployee::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId));

        $active = (clone $query)->where('lifecycle_state', 'active');

        return [
            'active' => (clone $active)->count(),
            'total_records' => (clone $query)->count(),
            'non_active' => (clone $query)->where('lifecycle_state', '!=', 'active')->count(),
            'by_organization' => (clone $active)
                ->selectRaw('organization_id, count(*) as total')
                ->groupBy('organization_id')
                ->pluck('total', 'organization_id')
                ->all(),
            'by_employment_type' => (clone $active)
                ->selectRaw('employment_type, count(*) as total')
                ->groupBy('employment_type')
                ->pluck('total', 'employment_type')
                ->all(),
            'by_worker_type' => (clone $active)
                ->selectRaw('worker_type, count(*) as total')
                ->groupBy('worker_type')
                ->pluck('total', 'worker_type')
                ->all(),
            'new_hires_this_year' => (clone $active)->whereYear('hire_date', now()->year)->count(),
        ];
    }

    /**
     * Turnover (§84): perpindahan keluar dibaca dari riwayat status Phase 1;
     * bila riwayatnya belum tersedia, dipakai perkiraan dari status pegawai
     * yang ditandai keluar pada rentang waktu tersebut.
     *
     * @return array<string, mixed>
     */
    public function turnover(string $from, string $to, ?int $organizationId = null): array
    {
        $separations = $this->separations($from, $to, $organizationId);
        $beginning = HrEmployee::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->where(fn ($q) => $q->whereNull('hire_date')->orWhere('hire_date', '<', $from))
            ->count();

        $ending = HrEmployee::query()
            ->where('lifecycle_state', 'active')
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->count();

        $average = max(1, (int) round(($beginning + $ending) / 2));
        $total = array_sum($separations);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'beginning_headcount' => $beginning,
            'ending_headcount' => $ending,
            'average_headcount' => $average,
            'separations' => $total,
            'by_type' => $separations,
            'turnover_rate' => round($total / $average * 100, 2),
            'note' => 'Jenis perpindahan memakai kategori pada riwayat status Phase 1.',
        ];
    }

    /** @return array<string, int> */
    private function separations(string $from, string $to, ?int $organizationId): array
    {
        $buckets = ['VOLUNTARY' => 0, 'INVOLUNTARY' => 0, 'RETIREMENT' => 0, 'CONTRACT_EXPIRY' => 0, 'OTHER' => 0];

        if (Schema::hasTable('hr_employee_status_histories')) {
            $rows = DB::table('hr_employee_status_histories')
                ->when(Schema::hasColumn('hr_employee_status_histories', 'effective_date'), fn ($q) => $q->whereBetween('effective_date', [$from, $to]))
                ->get();

            foreach ($rows as $row) {
                $status = strtoupper((string) ($row->new_status ?? $row->to_status ?? $row->status ?? 'OTHER'));

                $bucket = match (true) {
                    str_contains($status, 'RESIGN') => 'VOLUNTARY',
                    str_contains($status, 'TERMINAT'), str_contains($status, 'DISMISS') => 'INVOLUNTARY',
                    str_contains($status, 'RETIRE') => 'RETIREMENT',
                    str_contains($status, 'CONTRACT') => 'CONTRACT_EXPIRY',
                    default => 'OTHER',
                };

                $buckets[$bucket]++;
            }

            if (array_sum($buckets) > 0) {
                return $buckets;
            }
        }

        $fallback = HrEmployee::query()
            ->where('lifecycle_state', '!=', 'active')
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereBetween('updated_at', [$from, Carbon::parse($to)->endOfDay()])
            ->count();

        $buckets['OTHER'] = $fallback;

        return $buckets;
    }

    /** @return array<string, mixed> */
    public function attendance(string $from, string $to, ?int $organizationId = null): array
    {
        $records = HrAttendanceRecord::query()
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->whereBetween('date', [$from, $to])
            ->get();

        $days = max(1, $records->count());

        return [
            'records' => $records->count(),
            'present' => $records->whereIn('status', ['PRESENT', 'REMOTE'])->count(),
            'late' => $records->where('status', 'LATE')->count(),
            'absent' => $records->where('status', 'ABSENT')->count(),
            'leave' => $records->where('status', 'LEAVE')->count(),
            'business_trip' => $records->where('status', 'BUSINESS_TRIP')->count(),
            'missing_clock' => $records->whereIn('status', ['MISSING_CLOCK_IN', 'MISSING_CLOCK_OUT'])->count(),
            'late_minutes' => (int) $records->sum('late_minutes'),
            'worked_hours' => round($records->sum('worked_minutes') / 60, 2),
            'absenteeism_rate' => round($records->where('status', 'ABSENT')->count() / $days * 100, 2),
            'trend' => $records->groupBy(fn (HrAttendanceRecord $record) => $record->date?->format('Y-m'))->map(fn ($group) => [
                'present' => $group->whereIn('status', ['PRESENT', 'REMOTE'])->count(),
                'absent' => $group->where('status', 'ABSENT')->count(),
                'late' => $group->where('status', 'LATE')->count(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function leave(string $from, string $to, ?int $organizationId = null): array
    {
        $balances = HrLeaveBalance::query()
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->where('period_code', '>=', Carbon::parse($from)->format('Y-m'))
            ->get();

        $requests = DB::table('hr_leave_requests')
            ->when($organizationId !== null, fn ($q) => $q->whereIn('employee_id', HrEmployee::query()->where('organization_id', $organizationId)->pluck('id')))
            ->whereBetween('start_date', [$from, $to])
            ->get();

        return [
            'requests' => $requests->count(),
            'approved' => $requests->where('status', 'APPROVED')->count(),
            'rejected' => $requests->where('status', 'REJECTED')->count(),
            'pending' => $requests->whereIn('status', ['SUBMITTED', 'DRAFT'])->count(),
            'days_requested' => round((float) $requests->sum('days'), 2),
            'balance_used' => round((float) $balances->sum('used_days'), 2),
            'balance_available' => round((float) $balances->sum('available_days'), 2),
            'utilization' => round((float) $balances->sum('used_days') / max(1, (float) $balances->sum('entitlement_days')) * 100, 2),
            'by_type' => $requests->groupBy('leave_type_id')->map(fn ($group) => round((float) $group->sum('days'), 2))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function overtime(string $from, string $to, ?int $organizationId = null): array
    {
        $records = HrOvertimeRecord::query()
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->whereBetween('date', [$from, $to])
            ->get();

        return [
            'records' => $records->count(),
            'verified' => $records->where('status', 'VERIFIED')->count(),
            'pending' => $records->where('status', 'PENDING')->count(),
            'hours' => round($records->sum('verified_minutes') / 60, 2),
            'amount' => round((float) $records->sum('amount'), 2),
            'by_type' => $records->groupBy('multiplier')->map(fn ($group) => round($group->sum('verified_minutes') / 60, 2))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function payroll(?int $organizationId = null): array
    {
        $runs = HrPayrollRun::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderByDesc('id')
            ->limit(12)
            ->get();

        return [
            'runs' => $runs->count(),
            'latest' => $runs->first()?->only(['code', 'status', 'employee_count', 'gross_total', 'net_total', 'tax_total', 'deduction_total']),
            'trend' => $runs->map(fn (HrPayrollRun $run) => [
                'code' => $run->code,
                'period' => $run->period?->code,
                'employees' => (int) $run->employee_count,
                'gross' => (float) $run->gross_total,
                'net' => (float) $run->net_total,
                'cost' => (float) $run->employer_cost_total,
            ])->all(),
            'total_gross' => round((float) $runs->sum('gross_total'), 2),
            'total_net' => round((float) $runs->sum('net_total'), 2),
            'total_employer_cost' => round((float) $runs->sum('employer_cost_total'), 2),
        ];
    }

    /** @return array<string, mixed> */
    public function workforceCost(string $from, string $to, ?int $organizationId = null): array
    {
        $payroll = $this->payroll($organizationId);
        $training = $this->learning->analytics($organizationId);
        $overtime = $this->overtime($from, $to, $organizationId);
        $headcount = $this->headcount($organizationId)['active'];

        $salaryCost = (float) ($payroll['total_gross'] ?? 0);
        $benefitCost = 0.0;

        if (Schema::hasTable('hr_benefit_enrollments')) {
            $benefitCost = (float) DB::table('hr_benefit_enrollments')
                ->whereIn('status', ['ENROLLED', 'APPROVED'])
                ->sum('employer_contribution');
        }

        $total = $salaryCost + $benefitCost + (float) $overtime['amount'] + (float) $training['training_cost'];

        return [
            'salary_cost' => round($salaryCost, 2),
            'benefit_cost' => round($benefitCost, 2),
            'overtime_cost' => round((float) $overtime['amount'], 2),
            'training_cost' => round((float) $training['training_cost'], 2),
            'recruitment_cost' => 0.0,
            'total_cost' => round($total, 2),
            'headcount' => $headcount,
            'cost_per_employee' => $headcount > 0 ? round($total / $headcount, 2) : 0.0,
        ];
    }

    /**
     * Menulis snapshot harian untuk dibaca layar tren.
     *
     * @return list<HrAnalyticsSnapshot>
     */
    public function snapshot(?int $organizationId = null): array
    {
        $date = now()->toDateString();
        $scopes = [
            'WORKFORCE' => $this->headcount($organizationId),
            'ATTENDANCE' => $this->attendance(now()->startOfMonth()->toDateString(), $date, $organizationId),
            'LEAVE' => $this->leave(now()->startOfYear()->toDateString(), $date, $organizationId),
            'PAYROLL' => $this->payroll($organizationId),
            'PERFORMANCE' => $this->performance->summary(null, $organizationId),
            'LEARNING' => $this->learning->analytics($organizationId),
            'TALENT' => $this->talent->dashboard($organizationId),
        ];

        $snapshots = [];

        foreach ($scopes as $scope => $metrics) {
            $snapshots[] = HrAnalyticsSnapshot::query()->updateOrCreate(
                ['snapshot_date' => $date, 'scope' => $scope, 'organization_id' => $organizationId],
                [
                    'metrics' => $metrics,
                    'headcount' => (int) ($metrics['active'] ?? 0),
                    'cost_total' => (float) ($metrics['total_cost'] ?? ($metrics['total_gross'] ?? 0)),
                ],
            );
        }

        return $snapshots;
    }
}
