<?php

namespace App\Modules\Hrd\Http\Controllers;

use App\Modules\Hrd\Application\Services\CompetencyService as Phase1CompetencyService;
use App\Modules\Hrd\Core\Services\AIHRService;
use App\Modules\Hrd\Core\Services\AlertService;
use App\Modules\Hrd\Core\Services\HRServiceCenterService;
use App\Modules\Hrd\Core\Services\WorkforceAnalyticsService;
use App\Modules\Hrd\Benefits\Services\BenefitService;
use App\Modules\Hrd\Career\Services\CareerService;
use App\Modules\Hrd\Learning\Services\LearningService;
use App\Modules\Hrd\Leave\Services\HolidayCalendarService;
use App\Modules\Hrd\Leave\Services\LeavePolicyService;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Payroll\Models\HrPayrollRun;
use App\Modules\Hrd\Payroll\Services\PayrollService;
use App\Modules\Hrd\Performance\Services\PerformanceService;
use App\Modules\Hrd\Recruitment\Services\RecruitmentAnalyticsService;
use App\Modules\Hrd\Talent\Services\TalentService;
use App\Modules\Hrd\Workforce\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Permukaan API Phase 2.
 *
 * Endpoint baca untuk dasbor, analitik, dan data lintas domain. Penulisan tetap
 * lewat layanan domain (transaksi, cuti, payroll, kinerja) — controller ini
 * tidak pernah menyentuh tabel langsung, sehingga aturan bisnis tidak dapat
 * dilewati oleh pemanggil API.
 */
class HrdPhase2Controller
{
    public function __construct(
        private readonly WorkforceAnalyticsService $analytics,
        private readonly AlertService $alerts,
        private readonly HRServiceCenterService $serviceCenter,
        private readonly AIHRService $ai,
    ) {}

    public function meta(): JsonResponse
    {
        return $this->ok([
            'phase' => 2,
            'providers' => [
                'attendance' => config('hrd_phase2.attendance.providers'),
                'learning' => config('hrd_phase2.learning.provider'),
                'tax' => config('hrd_phase2.payroll.tax_provider'),
                'ai' => config('hrd_phase2.ai.provider'),
            ],
            'pipeline' => config('hrd_phase2.recruitment.pipeline'),
            'ai' => $this->ai->capabilities(),
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $organizationId = $request->integer('organization_id') ?: null;

        return $this->ok($this->analytics->dashboard($organizationId, $request->string('from')->toString() ?: null, $request->string('to')->toString() ?: null));
    }

    public function analytics(Request $request, string $scope): JsonResponse
    {
        $organizationId = $request->integer('organization_id') ?: null;
        $from = $request->string('from')->toString() ?: now()->startOfYear()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();

        $payload = match ($scope) {
            'headcount' => $this->analytics->headcount($organizationId),
            'turnover' => $this->analytics->turnover($from, $to, $organizationId),
            'attendance' => $this->analytics->attendance($from, $to, $organizationId),
            'leave' => $this->analytics->leave($from, $to, $organizationId),
            'overtime' => $this->analytics->overtime($from, $to, $organizationId),
            'payroll' => $this->analytics->payroll($organizationId),
            'workforce_cost' => $this->analytics->workforceCost($from, $to, $organizationId),
            default => null,
        };

        if ($payload === null) {
            return $this->ok(['error' => 'Cakupan analitik tidak dikenal.'], 404);
        }

        return $this->ok($payload);
    }

    public function alerts(Request $request): JsonResponse
    {
        return $this->ok([
            'open' => $this->alerts->open($request->integer('organization_id') ?: null),
        ]);
    }

    public function acknowledgeAlert(Request $request, int $alert): JsonResponse
    {
        return $this->ok($this->alerts->acknowledge($alert, (int) $request->user()->getKey(), $request->string('notes')->toString() ?: null)?->toArray());
    }

    public function serviceQueue(): JsonResponse
    {
        return $this->ok($this->serviceCenter->queue());
    }

    public function recruitmentAnalytics(Request $request, RecruitmentAnalyticsService $analytics): JsonResponse
    {
        return $this->ok($analytics->dashboard(
            $request->integer('organization_id') ?: null,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        ));
    }

    public function attendanceSummary(Request $request, AttendanceService $attendance, int $employee): JsonResponse
    {
        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();

        return $this->ok([
            'summary' => $attendance->attendanceSummary($employee, $from, $to),
            'context' => $attendance->contextFor($employee, now()->toDateString()),
        ]);
    }

    public function attendanceReconcile(string $date, AttendanceService $attendance): JsonResponse
    {
        return $this->ok($attendance->reconcile($date));
    }

    public function leaveBalances(Request $request, LeavePolicyService $policies, int $employee): JsonResponse
    {
        return $this->ok([
            'period' => $request->string('period')->toString() ?: now()->format('Y-m'),
            'balances' => $policies->balances($employee, $request->string('period')->toString() ?: null),
        ]);
    }

    public function leaveCalendar(Request $request, HolidayCalendarService $holidays): JsonResponse
    {
        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: now()->endOfMonth()->toDateString();

        return $this->ok([
            'holidays' => $holidays->inRange($from, $to, $request->integer('organization_id') ?: null),
        ]);
    }

    public function payrollRuns(Request $request): JsonResponse
    {
        $runs = HrPayrollRun::query()
            ->with('period')
            ->when($request->integer('organization_id') ?: null, fn ($query, $organizationId) => $query->where('organization_id', $organizationId))
            ->orderByDesc('id')
            ->limit(24)
            ->get();

        return $this->ok($runs->map(fn (HrPayrollRun $run) => [
            'code' => $run->code,
            'period' => $run->period?->code,
            'status' => $run->status,
            'employees' => (int) $run->employee_count,
            'gross' => (float) $run->gross_total,
            'net' => (float) $run->net_total,
            'tax' => (float) $run->tax_total,
            'variance_flagged' => collect($run->variance_snapshot ?? [])->contains(fn ($row) => ($row['flagged'] ?? false) === true),
        ])->all());
    }

    public function payrollReconciliation(int $run, PayrollService $payroll): JsonResponse
    {
        $model = HrPayrollRun::query()->findOrFail($run);

        return $this->ok($payroll->reconciliation($model));
    }

    public function payslip(Request $request, PayrollService $payroll, int $employee): JsonResponse
    {
        $period = $request->string('period')->toString() ?: now()->format('Y-m');

        return $this->ok([
            'net_pay' => $payroll->netPay($employee, $period),
            'payslip_path' => $payroll->payslipPath($employee, $period),
        ]);
    }

    public function benefitsSummary(BenefitService $benefits): JsonResponse
    {
        return $this->ok($benefits->summary());
    }

    public function benefitEligibility(int $employee, BenefitService $benefits): JsonResponse
    {
        return $this->ok(['plans' => $benefits->eligiblePlans($employee), 'enrollments' => $benefits->activeEnrollments($employee)]);
    }

    public function performanceSummary(Request $request, PerformanceService $performance): JsonResponse
    {
        return $this->ok($performance->summary($request->string('cycle')->toString() ?: null, $request->integer('organization_id') ?: null));
    }

    public function nineBox(Request $request, PerformanceService $performance): JsonResponse
    {
        return $this->ok($performance->nineBox($request->integer('organization_id') ?: null, $request->string('cycle')->toString() ?: null));
    }

    public function trainingMatrix(Request $request, LearningService $learning): JsonResponse
    {
        return $this->ok($learning->trainingMatrix($request->integer('organization_id') ?: null, $request->integer('department_id') ?: null));
    }

    public function learningAnalytics(Request $request, LearningService $learning): JsonResponse
    {
        return $this->ok($learning->analytics($request->integer('organization_id') ?: null));
    }

    public function careerPath(int $position, CareerService $career): JsonResponse
    {
        return $this->ok($career->pathFor($position));
    }

    public function careerReadiness(int $employee, int $position, CareerService $career): JsonResponse
    {
        return $this->ok($career->readiness($employee, $position));
    }

    public function talentDashboard(Request $request, TalentService $talent): JsonResponse
    {
        return $this->ok($talent->dashboard($request->integer('organization_id') ?: null));
    }

    public function selfService(Request $request, AttendanceService $attendance, LeavePolicyService $policies, PerformanceService $performance, LearningService $learning): JsonResponse
    {
        $user = $request->user();
        $employee = HrEmployee::query()->where('user_id', $user?->getKey())->first();

        if ($employee === null) {
            return $this->ok(['error' => 'Akun ini belum tertaut ke data pegawai.'], 404);
        }

        return $this->ok([
            'employee' => ['id' => $employee->getKey(), 'number' => $employee->employee_number, 'name' => $employee->displayName()],
            'attendance' => $attendance->attendanceSummary((int) $employee->getKey(), now()->startOfMonth()->toDateString(), now()->toDateString()),
            'today' => $attendance->contextFor((int) $employee->getKey(), now()->toDateString()),
            'leave_balances' => $policies->balances((int) $employee->getKey()),
            'goals' => $performance->goalProgress((int) $employee->getKey()),
            'training' => $learning->trainingCompliance((int) $employee->getKey()),
        ]);
    }

    public function aiSuggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'capability' => ['required', 'string'],
            'input' => ['required', 'array'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $employee = isset($validated['employee_id']) ? HrEmployee::query()->find($validated['employee_id']) : null;

        return $this->ok($this->ai->suggest($validated['capability'], $validated['input'], $request->user(), $employee));
    }

    public function competencies(Request $request): JsonResponse
    {
        return $this->ok(app(Phase1CompetencyService::class)->skillsInOrganization($request->integer('organization_id') ?: 0, $request->string('skill')->toString() ?: null));
    }

    /** @param array<string, mixed> $payload */
    private function ok(array $payload, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $payload], $status);
    }
}
