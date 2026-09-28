<?php

namespace App\Http\Controllers\Api;

use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * School-wide dashboard analytics. Each section is included only when the
 * authenticated user can read that domain; dashboard visibility alone never
 * grants access to student, attendance, or finance aggregates.
 */
class DashboardController extends BaseApiController
{
    public function overview(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $actor = $request->user();
        $attendanceDays = (int) $request->query('days', 14);
        if (! in_array($attendanceDays, [7, 14, 30], true)) {
            $attendanceDays = 14;
        }

        $data = [
            'as_of' => $today->toDateString(),
            'attendance_days' => $attendanceDays,
        ];

        if ($actor->can('student.view')) {
            $data['students'] = $this->studentAnalytics($today);
        }

        if ($actor->can('academic.view')) {
            $data['academic'] = $this->academicAnalytics();
            $data['attendance'] = $this->attendanceAnalytics($today, $attendanceDays);
        }

        $canViewAllFinance = $actor->can('finance.view');
        $canViewPayments = $canViewAllFinance || $actor->can('finance.payment.view');
        $canViewInvoices = $canViewAllFinance || $actor->can('finance.invoice.view');

        if ($canViewPayments || $canViewInvoices) {
            $data['finance'] = $this->financeAnalytics($today, $canViewPayments, $canViewInvoices);
        }

        return $this->successResponse($data, 'Ringkasan dashboard berhasil diambil');
    }

    /** @return array<string, mixed> */
    private function studentAnalytics(Carbon $today): array
    {
        $monthStart = $today->copy()->startOfMonth();
        $months = $this->recentMonths($today, 6);
        $rangeStart = Carbon::createFromFormat('Y-m', $months[0]['period'])->startOfMonth();
        $admissions = Student::query()
            ->whereNotNull('admission_date')
            ->whereDate('admission_date', '>=', $rangeStart->toDateString())
            ->whereDate('admission_date', '<=', $today->toDateString())
            ->pluck('admission_date')
            ->map(static fn ($date): string => substr((string) $date, 0, 7))
            ->countBy();

        $trend = array_map(static fn (array $month): array => [
            ...$month,
            'count' => (int) ($admissions[$month['period']] ?? 0),
        ], $months);

        return [
            'active_total' => Student::query()->active()->count(),
            'admitted_this_month' => Student::query()
                ->whereDate('admission_date', '>=', $monthStart->toDateString())
                ->whereDate('admission_date', '<=', $today->toDateString())
                ->count(),
            'admitted_last_month' => Student::query()
                ->whereDate('admission_date', '>=', $monthStart->copy()->subMonth()->toDateString())
                ->whereDate('admission_date', '<=', $monthStart->copy()->subDay()->toDateString())
                ->count(),
            'admissions_trend' => $trend,
        ];
    }

    /** @return array<string, mixed> */
    private function academicAnalytics(): array
    {
        $activeYear = AcademicYear::query()->where('is_active', true)->first();
        $classes = SchoolClass::query()->where('is_active', true);
        if ($activeYear) {
            $classes->where('academic_year_id', $activeYear->id);
        }

        $enrolledTotal = $activeYear
            ? (int) DB::table('class_students as cs')
                ->join('school_classes as sc', 'sc.id', '=', 'cs.school_class_id')
                ->join('students as st', 'st.id', '=', 'cs.student_id')
                ->where('cs.academic_year_id', $activeYear->id)
                ->where('cs.status', 'aktif')
                ->where('sc.is_active', true)
                ->whereNull('sc.deleted_at')
                ->where('st.is_active', true)
                ->whereNull('st.deleted_at')
                ->distinct()
                ->count('cs.student_id')
            : 0;

        return [
            'academic_year' => $activeYear?->name,
            'active_classes' => $classes->count(),
            'enrolled_students' => $enrolledTotal,
        ];
    }

    /** @return array<string, mixed> */
    private function attendanceAnalytics(Carbon $today, int $days): array
    {
        $start = $today->copy()->subDays($days - 1);
        $rows = DB::table('student_attendances as a')
            ->join('attendance_statuses as s', 's.id', '=', 'a.attendance_status_id')
            ->whereDate('a.date', '>=', $start->toDateString())
            ->whereDate('a.date', '<=', $today->toDateString())
            ->selectRaw('a.date as attendance_date, s.code as code, COUNT(*) as total')
            ->groupBy('a.date', 's.code')
            ->get();

        $perDay = [];
        $summary = [];
        foreach ($rows as $row) {
            $date = substr((string) $row->attendance_date, 0, 10);
            $code = (string) $row->code;
            $count = (int) $row->total;
            $perDay[$date][$code] = $count;
            $summary[$code] = ($summary[$code] ?? 0) + $count;
        }

        $daily = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->copy()->addDays($offset)->toDateString();
            $counts = $perDay[$date] ?? [];
            $total = array_sum($counts);
            $present = (int) ($counts[AttendanceStatus::CODE_PRESENT] ?? 0)
                + (int) ($counts[AttendanceStatus::CODE_LATE] ?? 0);

            $daily[] = [
                'date' => $date,
                'total' => $total,
                'present' => $present,
                'rate' => $total > 0 ? round(($present / $total) * 100, 1) : null,
            ];
        }

        $statusRows = AttendanceStatus::query()->orderBy('id')->get(['code', 'name', 'color']);
        $statuses = $statusRows->map(static fn (AttendanceStatus $status): array => [
            'code' => (string) $status->code,
            'name' => (string) $status->name,
            'color' => $status->color,
            'total' => (int) ($summary[$status->code] ?? 0),
        ])->all();

        $total = array_sum($summary);
        $present = (int) ($summary[AttendanceStatus::CODE_PRESENT] ?? 0)
            + (int) ($summary[AttendanceStatus::CODE_LATE] ?? 0);
        $todayPoint = $daily[array_key_last($daily)] ?? null;

        return [
            'days' => $days,
            'daily' => $daily,
            'summary' => [
                'total' => $total,
                'present' => $present,
                'rate' => $total > 0 ? round(($present / $total) * 100, 1) : null,
                'statuses' => $statuses,
            ],
            'today' => [
                'total' => (int) ($todayPoint['total'] ?? 0),
                'rate' => $todayPoint['rate'] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function financeAnalytics(Carbon $today, bool $canViewPayments, bool $canViewInvoices): array
    {
        $months = $this->recentMonths($today, 6);
        $rangeStart = Carbon::createFromFormat('Y-m', $months[0]['period'])->startOfMonth();
        $rangeEnd = $today->copy()->endOfDay();
        $receivedByMonth = [];
        $billedByMonth = [];

        if ($canViewPayments) {
            $receivedByMonth = $this->monthlySums(
                'finance_payments',
                'paid_at',
                'amount',
                $rangeStart,
                $rangeEnd,
                static fn (Builder $query) => $query->whereIn('status', [
                    FinancePayment::STATUS_PAID,
                    FinancePayment::STATUS_PARTIAL,
                ]),
            );
        }

        if ($canViewInvoices) {
            $billedByMonth = $this->monthlySums(
                'finance_invoices',
                'issue_date',
                'total',
                $rangeStart,
                $rangeEnd,
                static fn (Builder $query) => $query->whereIn('status', [
                    FinanceInvoice::STATUS_ISSUED,
                    FinanceInvoice::STATUS_PARTIAL,
                    FinanceInvoice::STATUS_PAID,
                    FinanceInvoice::STATUS_OVERDUE,
                ]),
            );
        }

        $series = array_map(static fn (array $month): array => [
            ...$month,
            'received' => $canViewPayments ? (float) ($receivedByMonth[$month['period']] ?? 0) : null,
            'billed' => $canViewInvoices ? (float) ($billedByMonth[$month['period']] ?? 0) : null,
        ], $months);

        $currentMonth = $today->format('Y-m');
        $finance = [
            'series' => $series,
            'received_this_month' => $canViewPayments ? (float) ($receivedByMonth[$currentMonth] ?? 0) : null,
            'billed_this_month' => $canViewInvoices ? (float) ($billedByMonth[$currentMonth] ?? 0) : null,
        ];

        if ($canViewPayments) {
            $finance['pending_payments'] = FinancePayment::query()
                ->where('status', FinancePayment::STATUS_PENDING)
                ->count();
        }

        if ($canViewInvoices) {
            $outstanding = FinanceInvoice::query()
                ->whereIn('status', [
                    FinanceInvoice::STATUS_ISSUED,
                    FinanceInvoice::STATUS_PARTIAL,
                    FinanceInvoice::STATUS_OVERDUE,
                ]);
            $finance['outstanding_total'] = (float) $outstanding->sum('remaining_amount');
            $finance['outstanding_invoices'] = (int) $outstanding->count();
        }

        return $finance;
    }

    /**
     * Aggregate amounts per calendar month using a SQL date expression supported
     * by each configured database driver.
     *
     * @param  callable(Builder): Builder  $scope
     * @return array<string, float>
     */
    private function monthlySums(
        string $table,
        string $dateColumn,
        string $amountColumn,
        Carbon $from,
        Carbon $to,
        callable $scope,
    ): array {
        $driver = DB::connection()->getDriverName();
        $periodExpression = match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$dateColumn})",
            'pgsql' => "TO_CHAR({$dateColumn}, 'YYYY-MM')",
            default => "DATE_FORMAT({$dateColumn}, '%Y-%m')",
        };

        $query = DB::table($table);
        if ($dateColumn === 'issue_date') {
            $query->whereDate($dateColumn, '>=', $from->toDateString())
                ->whereDate($dateColumn, '<=', $to->toDateString());
        } else {
            $query->whereBetween($dateColumn, [
                $from->copy()->startOfDay()->toDateTimeString(),
                $to->copy()->endOfDay()->toDateTimeString(),
            ]);
        }
        $scope($query);

        return $query
            ->selectRaw("{$periodExpression} as period, COALESCE(SUM({$amountColumn}), 0) as amount")
            ->groupBy('period')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->period => (float) $row->amount])
            ->all();
    }

    /** @return list<array{period: string, label: string}> */
    private function recentMonths(Carbon $today, int $count): array
    {
        $months = [];
        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $month = $today->copy()->startOfMonth()->subMonths($offset);
            $months[] = [
                'period' => $month->format('Y-m'),
                'label' => $month->format('M'),
            ];
        }

        return $months;
    }
}
