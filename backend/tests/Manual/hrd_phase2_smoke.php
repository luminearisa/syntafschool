<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Modules\Hrd\Application\Services\AssignmentService;
use App\Modules\Hrd\Application\Services\EmployeeService;
use App\Modules\Hrd\Application\Services\LifecycleTransactionService;
use App\Modules\Hrd\Application\Services\OrganizationService;
use App\Modules\Hrd\Application\Services\PositionService;
use App\Modules\Hrd\Benefits\Services\BenefitService;
use App\Modules\Hrd\Career\Services\CareerService;
use App\Modules\Hrd\Core\Models\HrRule;
use App\Modules\Hrd\Core\Services\AIHRService;
use App\Modules\Hrd\Core\Services\AlertService;
use App\Modules\Hrd\Core\Services\EligibilityEngine;
use App\Modules\Hrd\Core\Services\HRServiceCenterService;
use App\Modules\Hrd\Core\Services\RuleEngine;
use App\Modules\Hrd\Core\Services\WorkforceAnalyticsService;
use App\Modules\Hrd\Learning\Services\LearningService;
use App\Modules\Hrd\Leave\Services\HolidayCalendarService;
use App\Modules\Hrd\Leave\Services\LeavePolicyService;
use App\Modules\Hrd\Leave\Services\LeaveRequestService;
use App\Modules\Hrd\Models\HrLeaveType;
use App\Modules\Hrd\Models\HrSkill;
use App\Modules\Hrd\Payroll\Services\PayrollService;
use App\Modules\Hrd\Payroll\Services\SalaryStructureService;
use App\Modules\Hrd\Performance\Services\PerformanceService;
use App\Modules\Hrd\Recruitment\Services\CandidatePipelineService;
use App\Modules\Hrd\Recruitment\Services\OfferService;
use App\Modules\Hrd\Recruitment\Services\RecruitmentAnalyticsService;
use App\Modules\Hrd\Recruitment\Services\RequisitionService;
use App\Modules\Hrd\Talent\Services\TalentService;
use App\Modules\Hrd\Workforce\Services\AttendanceService;
use App\Modules\Hrd\Workforce\Services\BusinessTripService;
use App\Modules\Hrd\Workforce\Services\OvertimeService;
use App\Modules\Hrd\Workforce\Services\ScheduleService;
use App\Modules\Hrd\Workforce\Services\TimesheetService;

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;

    if ($condition) {
        $pass++;
        print('  [OK]   '.$label.($detail !== '' ? ' → '.$detail : '').PHP_EOL);
    } else {
        $fail++;
        print('  [GAGAL] '.$label.($detail !== '' ? ' → '.$detail : '').PHP_EOL);
    }
}

function section(string $title): void
{
    print(PHP_EOL.'== '.$title.' =='.PHP_EOL);
}

function actor(string $email, array $roles): User
{
    $user = User::query()->firstOrCreate(
        ['email' => $email],
        ['name' => ucfirst(explode('@', $email)[0]), 'password' => bcrypt('secret-pass-123'), 'is_active' => true],
    );

    $user->syncRoles($roles);

    return $user;
}

$hr = actor('hrd.phase2@syntaf.test', ['HR_ADMIN']);
$payrollAdmin = actor('payroll.admin@syntaf.test', ['PAYROLL_ADMIN']);
$payrollReviewer = actor('payroll.reviewer@syntaf.test', ['PAYROLL_REVIEWER']);
$payrollApprover = actor('payroll.approver@syntaf.test', ['PAYROLL_APPROVER']);
$manager = actor('manager.smoke@syntaf.test', ['MANAGER']);

$organizations = app(OrganizationService::class);
$positions = app(PositionService::class);
$employees = app(EmployeeService::class);

// ------------------------------------------------------------------ persiapan
$foundation = $organizations->createOrganization(['code' => 'YYS-P2', 'name' => 'Yayasan Phase 2', 'type_code' => 'Foundation'], $hr);
$school = $organizations->createOrganization(['code' => 'SMP-P2', 'name' => 'SMP Phase 2', 'type_code' => 'School', 'parent_id' => $foundation->getKey()], $hr);
$department = $organizations->createDepartment(['code' => 'AKAD-P2', 'name' => 'Akademik P2', 'organization_id' => $school->getKey()], $hr);

$teacherPosition = $positions->create(['code' => 'GURU-P2', 'title' => 'Guru P2', 'organization_id' => $school->getKey(), 'department_id' => $department->getKey(), 'authorized_headcount' => 3, 'grade_code' => 'G3'], $hr);
$headPosition = $positions->create(['code' => 'KADEP-P2', 'title' => 'Kepala Departemen P2', 'organization_id' => $school->getKey(), 'department_id' => $department->getKey(), 'authorized_headcount' => 1, 'grade_code' => 'G5'], $hr);

$employee = $employees->create([
    'organization_id' => $school->getKey(), 'department_id' => $department->getKey(), 'position_id' => $teacherPosition->getKey(),
    'worker_type' => 'Teacher', 'employment_type' => 'Permanent', 'employment_status' => 'ACTIVE', 'lifecycle_state' => 'active',
    'hire_date' => now()->subYears(2)->toDateString(),
    'person' => ['full_name' => 'Guru Phase Dua', 'nik' => '3271000000000009', 'gender' => 'male', 'birth_date' => '1988-03-05', 'work_email' => 'guru.p2@syntaf.test'],
    'assignment' => ['position_id' => $teacherPosition->getKey(), 'organization_id' => $school->getKey(), 'department_id' => $department->getKey(), 'start_date' => now()->subYears(2)->toDateString()],
], $hr);

$employee->forceFill(['user_id' => $manager->getKey()])->save();
HrSkill::query()->firstOrCreate(['code' => 'PEDAGOGY'], ['name' => 'Pedagogi', 'category' => 'TEACHING', 'is_active' => true]);

// ------------------------------------------------------------------ 1. REKRUTMEN
section('1. Rekrutmen: requisition → offer → hire → employee');

try {
    $requisitions = app(RequisitionService::class);
    $pipeline = app(CandidatePipelineService::class);
    $offers = app(OfferService::class);

    $requisition = $requisitions->createFromRequest([
        'organization_id' => $school->getKey(), 'department_id' => $department->getKey(), 'position_id' => $teacherPosition->getKey(),
        'headcount' => 1, 'reason' => 'Penambahan rombel', 'budget' => 6000000,
    ], $hr);
    $requisitions->submit($requisition, $hr);
    $requisition = $requisitions->decide($requisition->refresh(), $hr, true, 'Disetujui');

    check('permintaan disetujui', in_array($requisition->status, ['APPROVED', 'OPEN'], true), $requisition->code.' ('.$requisition->status.')');
    check('lowongan terbuka otomatis', $requisition->openings()->count() === 1);

    $opening = $requisition->openings()->first();

    $candidate = $pipeline->register([
        'full_name' => 'Kandidat Guru Baru', 'email' => 'kandidat.p2@syntaf.test', 'phone' => '081234000111',
        'education_level' => 'S1', 'total_experience_years' => 4, 'source_code' => 'CAREER_WEBSITE',
        'skills' => [['code' => 'PEDAGOGY', 'level' => 'INTERMEDIATE']],
    ], $hr);

    $candidateAgain = $pipeline->register(['full_name' => 'Kandidat Guru Baru', 'email' => 'KANDIDAT.P2@syntaf.test'], $hr);
    check('kandidat tidak digandakan', $candidate->getKey() === $candidateAgain->getKey(), 'satu profil untuk satu surel');

    $application = $pipeline->apply($candidate, $opening->getKey(), $hr, ['source_code' => 'CAREER_WEBSITE']);
    $pipeline->screen($application, ['required' => ['EDUCATION', 'EXPERIENCE'], 'met' => ['EDUCATION', 'EXPERIENCE']], 'PASS', $hr, 'Memenuhi syarat');
    check('screening memindahkan tahap', $application->refresh()->stage_code === 'SHORTLISTED', 'skor '.$application->screening_score);

    $schedule = $pipeline->scheduleInterview($application->refresh(), [$hr->getKey(), $manager->getKey()], ['scheduled_at' => now()->addDay(), 'interview_type' => 'TEACHING_DEMO', 'mode' => 'ONSITE'], $hr);
    $pipeline->submitScorecard($schedule, $hr, ['scores' => [['item_label' => 'Komunikasi', 'weight' => 20, 'score' => 4], ['item_label' => 'Teknis', 'weight' => 30, 'score' => 5]], 'recommendation' => 'HIRE']);
    check('scorecard menaikkan skor wawancara', (float) $application->refresh()->interview_score > 4, 'rata-rata '.$application->interview_score);
    check('scorecard pewawancara lain tetap tertutup', app(CandidatePipelineService::class)->scorecardsFor($schedule, $manager) === []);

    $pipeline->recordAssessment($application->refresh(), 'TECHNICAL-TEST', ['score' => 85, 'reviewer_user_id' => $hr->getKey()], $hr);
    check('asesmen tercatat', $application->refresh()->assessment_score !== null, 'skor '.$application->assessment_score);

    $offer = $offers->draft($application->refresh(), ['offered_salary' => 5500000, 'start_date' => now()->addWeek()->toDateString(), 'employment_type' => 'Permanent', 'worker_type' => 'Teacher'], $hr);
    $offers->submitForApproval($offer, $hr);
    $offer = $offers->approve($offer->refresh(), $hr, 'Sesuai anggaran');
    $offers->send($offer->refresh(), $hr);
    $offer = $offers->respond($offer->refresh(), $hr, true);

    $hired = $application->refresh();
    check('tawaran diterima', $offer->status === 'ACCEPTED');
    check('kandidat menjadi pegawai', $hired->hired_employee_id !== null && $hired->status === 'HIRED', 'pegawai #'.$hired->hired_employee_id);
    check('formasi lowongan berkurang', (int) $opening->refresh()->filled_count === 1);

    $newEmployee = $employees->summaryCard(\App\Modules\Hrd\Models\HrEmployee::query()->find($hired->hired_employee_id));
    check('pegawai baru punya nomor & penugasan', ($newEmployee['employee_number'] ?? null) !== null, $newEmployee['employee_number'] ?? '-');

    $funnel = app(RecruitmentAnalyticsService::class);
    $analytics = $funnel->dashboard($school->getKey());
    check('analitik rekrutmen terhitung', isset($analytics['funnel'], $analytics['sources'], $analytics['time_to_hire_days']), 'pelamar '.$analytics['funnel']['total']);
} catch (Throwable $exception) {
    check('alur rekrutmen', false, $exception->getMessage());
}

// ------------------------------------------------------------------ 2. WORKFORCE
section('2. Workforce: jadwal → presensi → koreksi → lembur → timesheet → dinas');

try {
    $schedules = app(ScheduleService::class);
    $attendance = app(AttendanceService::class);

    $schedule = \App\Modules\Hrd\Workforce\Models\HrWorkSchedule::query()->where('code', 'REGULER')->first();
    $schedules->assign($employee, ['schedule_id' => $schedule?->getKey(), 'effective_from' => now()->subMonth()->toDateString()], $hr);

    $resolved = $schedules->resolveFor((int) $employee->getKey(), now()->toDateString());
    check('jadwal hari ini teresolusi', $resolved['schedule'] !== null && $resolved['start'] !== null, 'mulai '.$resolved['start']?->format('H:i'));

    $night = \App\Modules\Hrd\Workforce\Models\HrShift::query()->where('code', 'MALAM')->first();
    $window = $schedules->windowFor($night, now());
    check('shift lintas tengah malam', $window['end']->greaterThan($window['start']) && (bool) $night->cross_midnight, $window['start']->format('d H:i').' → '.$window['end']->format('d H:i'));

    $clockIn = $attendance->clockIn($employee, ['provider' => 'mobile', 'latitude' => -6.2, 'longitude' => 106.8], $manager);
    ['record_id' => $recordId] = $clockIn;
    check('jam masuk tercatat', $recordId !== null, 'status '.$clockIn['status']);

    sleep(1);
    $clockOut = $attendance->clockOut($employee, ['provider' => 'mobile'], $manager);
    $record = \App\Modules\Hrd\Workforce\Models\HrAttendanceRecord::query()->findOrFail($recordId);
    check('jam keluar & durasi kerja', $record->clock_out !== null && (int) $record->worked_minutes >= 0, $record->worked_minutes.' menit, status '.$record->status);

    $duplicate = $attendance->clockIn($employee, ['provider' => 'mobile', 'idempotency_key' => 'p2-dup-1'], $manager);
    $again = $attendance->clockIn($employee, ['provider' => 'mobile', 'idempotency_key' => 'p2-dup-1'], $manager);
    check('idempotensi perangkat', $again['duplicate'] === true);

    $correction = $attendance->requestCorrection($employee, ['date' => $record->date->toDateString(), 'correction_type' => 'FORGOT_CLOCK_IN', 'requested_clock_in' => now()->setTime(6, 55)->toDateTimeString(), 'reason' => 'Lupa tap masuk'], $manager);
    $attendance->decideCorrection($correction, $manager, true, 'Disetujui');
    check('koreksi diterapkan ke presensi', $correction->refresh()->applied_at !== null, 'sumber '.$record->refresh()->clock_in_source);

    // lembur nyata: perpanjang jam keluar agar melewati jam terjadwal
    $expectedEnd = $resolved['end'];
    $record->forceFill(['clock_out' => $expectedEnd?->copy()->addHours(2)])->save();
    $attendance->recalculate($record->refresh());
    check('lembur terdeteksi dari presensi', (int) $record->refresh()->overtime_minutes > 0, $record->overtime_minutes.' menit');

    $overtime = app(OvertimeService::class);
    $request = $overtime->request($employee, ['planned_start' => $expectedEnd?->copy()->toDateTimeString(), 'planned_end' => $expectedEnd?->copy()->addHours(2)->toDateTimeString(), 'reason' => 'Rapat kurikulum'], $manager);
    $request = $overtime->decide($request, $manager, true, 'Disetujui');
    $verified = $overtime->verifyActual($request->refresh(), $manager);
    check('lembur diverifikasi dari jam nyata', $verified->status === 'VERIFIED' && (int) $verified->verified_minutes > 0, $verified->verified_minutes.' menit, pengali '.$verified->multiplier);

    $timesheets = app(TimesheetService::class);
    $timesheet = $timesheets->create($employee, ['period_type' => 'WEEKLY'], $manager);
    $timesheets->addEntry($timesheet, ['work_date' => now()->toDateString(), 'hours' => 5, 'project_code' => 'PROYEK-A', 'is_billable' => true], $manager);
    $timesheets->addEntry($timesheet, ['work_date' => now()->toDateString(), 'hours' => 2, 'project_code' => 'PROYEK-B', 'is_billable' => false], $manager);
    $timesheets->submit($timesheet->refresh(), $manager);
    $timesheets->decide($timesheet->refresh(), $manager, true);
    check('timesheet & utilisasi', (float) $timesheet->refresh()->total_hours === 7.0, json_encode($timesheets->utilization((int) $employee->getKey(), now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString())));

    $trips = app(BusinessTripService::class);
    $start = now()->addWeeks(2)->startOfDay();
    $trip = $trips->create($employee, ['title' => 'Studi banding', 'destination' => 'Bandung', 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(), 'cost_estimate' => 1500000], $manager);
    $trips->submit($trip, $manager);
    $trip = $trips->decide($trip->refresh(), $hr, true, 'Disetujui');
    $tripRecord = \App\Modules\Hrd\Workforce\Models\HrAttendanceRecord::query()->where('employee_id', $employee->getKey())->where('date', $start->toDateString())->first();
    check('dinas mengubah presensi', $tripRecord?->status === 'BUSINESS_TRIP', 'status '.$tripRecord?->status);

    $reconcile = $attendance->reconcile($record->date->toDateString());
    check('rekonsiliasi presensi berjalan', isset($reconcile['findings'], $reconcile['totals']), 'temuan: '.json_encode($reconcile['totals']));
} catch (Throwable $exception) {
    check('alur workforce', false, $exception->getMessage().' @ '.$exception->getFile().':'.$exception->getLine());
}

// ------------------------------------------------------------------ 3. CUTI
section('3. Cuti: akrual → saldo → pengajuan → persetujuan → presensi');

try {
    $policies = app(LeavePolicyService::class);
    $leaves = app(LeaveRequestService::class);

    $annual = HrLeaveType::query()->where('code', 'ANNUAL')->first();
    $accrual = $policies->accrue($employee, $annual, now()->format('Y-m'), $hr);
    $accrualAgain = $policies->accrue($employee, $annual, now()->format('Y-m'), $hr);

    check('akrual cuti berjalan', ($accrual['accrued'] ?? 0) >= 1, 'akrual '.$accrual['accrued'].' hari');
    check('akrual idempoten', ($accrualAgain['reason'] ?? '') === 'ALREADY_ACCRUED');

    $before = $leaves->availableBalance((int) $employee->getKey(), 'ANNUAL');
    check('saldo cuti terhitung', $before >= 1, 'tersedia '.$before.' hari');

    $leaveStart = now()->addMonth()->startOfMonth();
    while ($leaveStart->isWeekend()) {
        $leaveStart->addDay();
    }

    $request = $leaves->request($employee, [
        'leave_type_code' => 'ANNUAL',
        'start_date' => $leaveStart->toDateString(),
        'end_date' => $leaveStart->copy()->addDays(2)->toDateString(),
        'reason' => 'Keperluan keluarga',
    ], $hr);

    check('pengajuan cuti dibuat', $request->status === 'SUBMITTED', $request->code.' ('.$request->days.' hari)');
    check('saldo pending naik', (float) $policies->balanceFor((int) $employee->getKey(), (int) $annual->getKey(), $policies->periodCode($leaveStart))->pending_days > 0);

    $decided = $leaves->decide($request->refresh(), $hr, true, 'Disetujui');
    check('cuti disetujui & saldo terpakai', $decided->status === 'APPROVED' && (float) $policies->balanceFor((int) $employee->getKey(), (int) $annual->getKey(), $policies->periodCode($leaveStart))->used_days > 0);
    check('cuti masuk kalender presensi', (bool) $decided->attendance_applied, 'presensi disinkronkan');

    $conflicts = $leaves->detectConflicts($employee, $leaveStart->copy(), $leaveStart->copy()->addDay(), $annual);
    check('deteksi konflik menemukan cuti yang ada', collect($conflicts)->contains(fn ($c) => $c['type'] === 'EXISTING_LEAVE'));

    $unpaid = HrLeaveType::query()->where('code', 'UNPAID')->first();
    $unpaidRequest = $leaves->request($employee, ['leave_type_code' => 'UNPAID', 'start_date' => now()->addMonths(2)->startOfMonth()->toDateString(), 'end_date' => now()->addMonths(2)->startOfMonth()->addDay()->toDateString(), 'reason' => 'Urusan pribadi'], $hr);
    $leaves->decide($unpaidRequest->refresh(), $hr, true);
    $unpaidDays = $leaves->unpaidLeaveDays((int) $employee->getKey(), now()->addMonths(2)->startOfMonth()->toDateString(), now()->addMonths(2)->endOfMonth()->toDateString());
    check('cuti tanpa upah terbaca payroll', $unpaidDays > 0, $unpaidDays.' hari');

    check('kalender hari libur', count(app(HolidayCalendarService::class)->inRange(now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString(), $school->getKey())) === 3);
} catch (Throwable $exception) {
    check('alur cuti', false, $exception->getMessage().' @ '.$exception->getFile().':'.$exception->getLine());
}

// ------------------------------------------------------------------ 4. PAYROLL
section('4. Payroll: periode → hitung → telaah → setuju → final → slip → jurnal');

try {
    $structures = app(SalaryStructureService::class);
    $payroll = app(PayrollService::class);

    $structures->assign($employee, ['base_amount' => 5000000, 'effective_from' => now()->startOfMonth()->toDateString(), 'reason' => 'Penetapan awal'], $payrollAdmin);

    $period = $payroll->openPeriod(['code' => now()->format('Y-m'), 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'working_days' => 22], $payrollAdmin);
    $run = $payroll->createRun($period, ['organization_id' => $school->getKey()], $payrollAdmin);
    $runAgain = $payroll->createRun($period, ['organization_id' => $school->getKey()], $payrollAdmin);
    check('menjalankan payroll idempoten', $run->getKey() === $runAgain->getKey(), $run->code);

    $run = $payroll->calculate($run, $payrollAdmin);
    $item = $run->items()->where('employee_id', $employee->getKey())->first();

    check('perhitungan menghasilkan item', $item !== null, 'pegawai dihitung '.$run->employee_count);
    check('gaji pokok diprorata', (float) $item->base_amount > 0 && (float) $item->base_amount <= 5000000, 'pokok '.$item->base_amount.' ('.$item->absent_days.' hari tidak hadir)');
    check('lembur masuk payroll', (float) $item->overtime_amount > 0, 'lembur '.$item->overtime_amount);
    check('pajak & potongan wajib', (float) $item->tax_total > 0, 'pajak '.$item->tax_total);
    check('upah bersih terhitung', (float) $item->net_amount > 0, 'neto '.$item->net_amount);

    $itemComponents = $item->components()->pluck('kind')->unique()->values()->all();
    check('komponen terinci per jenis', count($itemComponents) >= 3, implode(', ', $itemComponents));

    $reconciliation = $payroll->reconciliation($run);
    check('rekonsiliasi total', isset($reconciliation['current'], $reconciliation['variance']), 'bruto '.$reconciliation['current']['gross_total']);

    try {
        $payroll->review($run->refresh(), $payrollAdmin, true);
        check('pemisahan tugas penyusun ≠ pemeriksa', false, 'penyusun berhasil menelaah sendiri');
    } catch (Throwable $exception) {
        check('pemisahan tugas penyusun ≠ pemeriksa', str_contains($exception->getMessage(), 'Pemisahan tugas'));
    }

    $run = $payroll->review($run->refresh(), $payrollReviewer, true, 'Sudah diperiksa');
    $run = $payroll->approve($run->refresh(), $payrollApprover, 'Disetujui');
    $run = $payroll->finalize($run->refresh(), $payrollReviewer);

    check('payroll final & snapshot beku', $run->status === 'FINALIZED' && $run->items()->where('status', 'LOCKED')->count() > 0);
    check('slip gaji terbit', \App\Modules\Hrd\Payroll\Models\HrPayslip::query()->where('run_id', $run->getKey())->count() > 0);
    check('snapshot memuat masukan presensi', \App\Modules\Hrd\Payroll\Models\HrPayrollSnapshot::query()->where('run_id', $run->getKey())->first()?->checksum !== null);

    $posting = $payroll->postRunToFinance($run->refresh(), $payrollApprover);
    check('jurnal payroll terbentuk', $posting['status'] === 'PENDING' && count($posting['lines']) > 0, 'baris jurnal '.count($posting['lines']).' (Finance belum terpasang)');

    $adjustment = $payroll->adjust($employee, ['amount' => 250000, 'adjustment_type' => 'BONUS', 'component_code' => 'BONUS', 'period_id' => $period->getKey(), 'reason' => 'Bonus uji', 'idempotency_key' => 'p2-bonus-1'], $payrollAdmin);
    check('penyesuaian gaji tercatat', $adjustment->status === 'APPROVED', $adjustment->code);
    check('upah bersih dapat dibaca periode itu', $payroll->netPay((int) $employee->getKey(), $period->code) !== null, (string) $payroll->netPay((int) $employee->getKey(), $period->code));
} catch (Throwable $exception) {
    check('alur payroll', false, $exception->getMessage().' @ '.$exception->getFile().':'.$exception->getLine());
}

// ------------------------------------------------------------------ 5. BENEFIT
section('5. Benefit: kelayakan → pendaftaran → potongan payroll');

try {
    $benefits = app(BenefitService::class);
    $plan = \App\Modules\Hrd\Benefits\Models\HrBenefitPlan::query()->where('code', 'PLAN-HEALTH-EXTRA')->first();
    $bpjs = \App\Modules\Hrd\Benefits\Models\HrBenefitPlan::query()->where('code', 'PLAN-BPJS-KES')->first();

    $eligibility = $benefits->check($employee, $plan);
    check('kelayakan benefit diperiksa', $eligibility['eligible'], implode('; ', $eligibility['reasons']));

    $enrollment = $benefits->enrollEmployee($employee, $bpjs, ['dependents' => [['name' => 'Anak Uji', 'relationship' => 'CHILD', 'birth_date' => '2015-01-01']]], $hr);
    check('pendaftaran benefit berhasil', $enrollment->status === 'ENROLLED', $enrollment->code);
    check('tanggungan tercatat', $enrollment->dependents()->count() === 1);
    check('iuran menjadi komponen gaji', \App\Modules\Hrd\Payroll\Models\HrEmployeeSalaryComponent::query()->where('employee_id', $employee->getKey())->where('source', 'BENEFIT')->exists());
    check('potongan bulanan terhitung', $benefits->monthlyDeduction((int) $employee->getKey(), now()->format('Y-m')) > 0, (string) $benefits->monthlyDeduction((int) $employee->getKey(), now()->format('Y-m')));
} catch (Throwable $exception) {
    check('alur benefit', false, $exception->getMessage().' @ '.$exception->getFile().':'.$exception->getLine());
}

// ------------------------------------------------------------------ 6. KINERJA
section('6. Kinerja: siklus → sasaran/OKR → kompetensi → penilaian → kalibrasi → 9-box');

try {
    $performance = app(PerformanceService::class);

    $cycle = $performance->createCycle([
        'code' => 'CYC-P2-'.now()->year, 'name' => 'Siklus Phase 2 '.now()->year, 'cycle_type' => 'ANNUAL',
        'organization_id' => $school->getKey(), 'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(),
        'review_end' => now()->addDays(10)->toDateString(), 'weight_goals' => 60, 'weight_competency' => 40,
    ], $hr);
    $opened = $performance->openCycle($cycle, $hr);
    check('siklus kinerja dibuka', $opened['participants_created'] >= 1, 'peserta '.$opened['participants_total']);

    $goal = $performance->createGoal($employee, [
        'cycle_id' => $cycle->getKey(), 'title' => 'Meningkatkan capaian literasi siswa', 'goal_type' => 'OBJECTIVE',
        'metric' => 'Nilai rata-rata', 'unit' => 'poin', 'weight' => 60, 'due_date' => now()->addMonths(3)->toDateString(),
    ], $hr, [
        ['title' => 'Nilai rata-rata naik 10%', 'target_value' => 100, 'weight' => 60, 'current_value' => 40],
        ['title' => 'Semua siswa tuntas KKM', 'target_value' => 100, 'weight' => 40, 'current_value' => 100],
    ]);

    $kr = $goal->keyResults()->first();
    $goal = $performance->updateProgress($goal->refresh(), ['key_result_id' => $kr->getKey(), 'current_value' => 90, 'comment' => 'Progres baik'], $hr);

    check('kemajuan key result menggerakkan sasaran', (float) $goal->progress > 0, 'sasaran '.$goal->progress.'%, KR '.$kr->refresh()->progress.'%');
    check('OKR berbobot', abs(((float) $goal->keyResults()->sum('weight')) - 100) < 0.01, 'total bobot 100');

    $competency = $performance->assessCompetency($employee, ['competency_code' => 'CORE-PEDAGOGY', 'current_level' => 3], $hr);
    check('kompetensi dinilai & kesenjangan', (int) $competency->gap >= 0, 'sekarang '.$competency->current_level.' → target '.$competency->target_level.' (gap '.$competency->gap.')');
    check('kesenjangan kompetensi terbaca', $performance->competencyGaps((int) $employee->getKey())['assessed'] >= 1);

    $review = $performance->startReview($cycle->refresh(), $employee, $employee, 'MANAGER', $hr);
    $review = $performance->submitReview($review, ['strengths' => 'Kolaboratif', 'improvements' => 'Administrasi', 'status' => 'SUBMITTED'], $hr);

    check('penilaian memakai bobot siklus', (float) $review->overall_score > 0, 'akhir '.$review->overall_score.' ('.$review->rating_label.')');
    $performance->acknowledge($review->refresh(), $hr, 'Diterima');

    $calibration = $performance->createCalibration($cycle->refresh(), ['name' => 'Kalibrasi Semester 1'], $hr);
    $entry = $performance->calibrate($calibration, $employee, ['calibrated_rating' => 4.0, 'potential_rating' => 4.2, 'justification' => 'Konsisten melampaui target'], $hr);
    $performance->completeCalibration($calibration->refresh(), $hr);

    check('kalibrasi menghasilkan 9-box', $entry->nine_box_code === 'HIGH_HIGH', 'kotak '.$entry->nine_box_code);
    check('9-box dapat ditelusuri', isset($performance->nineBox($school->getKey(), $cycle->code)['cells'][$entry->nine_box_code]));

    $matrix = $performance->nineBox(null, $cycle->code);
    check('matriks 9-box memuat sumber data', ($matrix['cells'][$entry->nine_box_code][0]['source'] ?? null) === 'performance_cycle_participants');

    $plan = $performance->createDevelopmentPlan($employee, ['title' => 'Rencana Pengembangan P2', 'source' => 'PERFORMANCE', 'cycle_id' => $cycle->getKey()], $hr, [
        ['action_type' => 'TRAINING', 'title' => 'Ikut Literasi Digital Guru'],
    ]);
    check('rencana pengembangan dibuat', $plan->actions()->count() === 1);

    $pip = $performance->createPip($employee, $employee, ['issue' => 'Administrasi terlambat', 'expectations' => 'Tepat waktu'], $hr);
    $performance->reviewPip($pip, ['status' => 'ON_TRACK', 'progress' => 60], $hr);
    check('PIP aktif & ditinjau', $performance->pipActive((int) $employee->getKey()) && $pip->reviews()->count() === 1);

    check('ringkasan kinerja', isset($performance->summary($cycle->code)['average_rating']), json_encode($performance->summary($cycle->code)['rating_distribution']));
} catch (Throwable $exception) {
    check('alur kinerja', false, $exception->getMessage().' @ '.$exception->getFile().':'.$exception->getLine());
}

// ------------------------------------------------------------------ 7. PEMBELAJARAN
section('7. Pembelajaran: pelatihan wajib → permintaan → penyelesaian → keterampilan');

try {
    $learning = app(LearningService::class);

    $required = $learning->requiredTraining((int) $employee->getKey());
    check('pelatihan wajib terdeteksi', count($required) >= 1, count($required).' pelatihan wajib');

    $request = $learning->requestFor($employee, ['training_code' => 'DIGITAL-LITERACY', 'justification' => 'Menunjang kelas digital'], $hr);
    $learning->decideRequest($request, $hr, 'MANAGER', true, 'Disetujui atasan');
    $request = $learning->decideRequest($request->refresh(), $hr, 'HR', true, 'Disetujui L&D');

    $assignment = \App\Modules\Hrd\Learning\Models\HrTrainingAssignment::query()->where('request_id', $request->getKey())->first();
    check('permintaan pelatihan → pendaftaran', $assignment !== null, 'status '.$assignment?->status);

    $again = $learning->assign($request->refresh(), $hr);
    check('pendaftaran pelatihan idempoten', $again->getKey() === $assignment->getKey());

    \App\Modules\Hrd\Learning\Models\HrTrainingCatalog::query()->where('code', 'DIGITAL-LITERACY')->update(['skills' => [['code' => 'PEDAGOGY', 'level' => 'ADVANCED']]]);

    $completion = $learning->complete((int) $assignment->getKey(), ['score' => 92, 'assessment_type' => 'POST_TEST', 'skills' => [['code' => 'PEDAGOGY', 'level' => 'ADVANCED']], 'notes' => 'Sangat baik'], $hr->getKey());

    check('pelatihan selesai & lulus', $completion['result'] === 'PASS', 'skor 92');
    check('keterampilan diperbarui di Phase 1', $completion['skills_updated'] === true && \App\Modules\Hrd\Models\HrEmployeeSkill::query()->where('employee_id', $employee->getKey())->where('source', 'training')->exists());
    check('kepatuhan pelatihan terhitung', isset($learning->trainingCompliance((int) $employee->getKey())['compliant']), 'jam pelatihan '.$learning->trainingHours((int) $employee->getKey(), now()->startOfYear()->toDateString(), now()->toDateString()));
    check('matriks pelatihan', count($learning->trainingMatrix($school->getKey())) >= 1);
    check('analitik pembelajaran', $learning->analytics($school->getKey())['completed'] >= 1, json_encode($learning->analytics($school->getKey())['completion_rate']));
} catch (Throwable $exception) {
    check('alur pembelajaran', false, $exception->getMessage().' @ '.$exception->getFile().':'.$exception->getLine());
}

// ------------------------------------------------------------------ 8. KARIER & TALENTA
section('8. Karier & talenta: jalur → kesiapan → mobilitas internal → talenta');

try {
    $career = app(CareerService::class);

    $path = $career->pathFor((int) $headPosition->getKey());
    check('jalur karier ditemukan', $path['path'] !== null || $path['next_steps'] !== null, json_encode($path['path_code'] ?? null));

    \App\Modules\Hrd\Performance\Models\HrPositionSkillRequirement::query()->create([
        'position_id' => $headPosition->getKey(), 'skill_code' => 'PEDAGOGY', 'required_level' => 'EXPERT', 'weight' => 1, 'is_mandatory' => true,
    ]);

    $readiness = $career->readiness((int) $employee->getKey(), (int) $headPosition->getKey());
    check('kesiapan karier dihitung', isset($readiness['readiness_score'], $readiness['skill_gaps']), 'skor '.$readiness['readiness_score'].' ('.$readiness['readiness_level'].'), gap '.count($readiness['skill_gaps']));

    $openings = app(RequisitionService::class);
    $internalRequisition = $openings->createFromRequest(['organization_id' => $school->getKey(), 'position_id' => $headPosition->getKey(), 'headcount' => 1, 'reason' => 'Rotasi jabatan'], $hr);
    $openings->submit($internalRequisition, $hr);
    $internalRequisition = $openings->decide($internalRequisition->refresh(), $hr, true);
    $internalOpening = $openings->publishOpening($internalRequisition->refresh(), ['visibility' => 'INTERNAL', 'title' => 'Kepala Departemen (internal)'], $hr);

    $internal = $career->applyInternal((int) $employee->getKey(), (int) $internalOpening->getKey(), ['notes' => 'Siap memimpin'], $hr->getKey());
    check('lamaran internal dibuat', $internal['status'] === 'MANAGER_NOTIFIED', $internal['status']);
    check('kandidat internal memakai pegawai yang sama', \App\Modules\Hrd\Recruitment\Models\HrCandidateApplication::query()->find($internal['application_id'])?->internal_employee_id === $employee->getKey());

    $transfer = $career->transfer(\App\Modules\Hrd\Career\Models\HrInternalJobApplication::query()->findOrFail($internal['internal_application_id']), $hr);
    $employee->refresh();
    check('perpindahan dijalankan mesin transaksi', $transfer['status'] === 'completed', 'transaksi '.$transfer['transaction_id']);
    check('posisi pegawai berpindah', (int) $employee->position_id === (int) $headPosition->getKey(), 'posisi sekarang '.$employee->position?->code);
    check('riwayat penugasan terjaga', $employee->assignments()->count() >= 2, $employee->assignments()->count().' penugasan');

    $talent = app(TalentService::class);
    $reviewModel = $talent->createReview(['name' => 'Tinjauan Talenta P2', 'cycle_id' => \App\Modules\Hrd\Performance\Models\HrPerformanceCycle::query()->where('code', 'CYC-P2-'.now()->year)->value('id'), 'review_type' => 'PERFORMANCE_POTENTIAL'], $hr);
    $talent->recordPotential($employee, ['potential_score' => 4.5, 'learning_agility' => 4.0, 'summary' => 'Cepat belajar'], $hr);
    $result = $talent->recordResult($reviewModel, $employee, ['potential_rating' => 4.5, 'readiness' => 'READY_NOW', 'risk_of_loss' => 'MEDIUM', 'impact_of_loss' => 'HIGH'], $hr);
    $talent->completeReview($reviewModel->refresh(), $hr, 'Selesai');

    check('hasil tinjauan talenta & 9-box', $result->nine_box_code !== null, 'kotak '.$result->nine_box_code);

    $pool = \App\Modules\Hrd\Talent\Models\HrTalentPool::query()->where('pool_type', 'HIGH_POTENTIAL')->first();
    $talent->addToPool($pool, $employee, ['readiness' => 'READY_NOW', 'rank' => 1], $hr);
    check('anggota kumpulan talenta', count($talent->poolMembers('HIGH_POTENTIAL')) === 1);
    check('potensi tinggi terdaftar', count($talent->highPotentials($school->getKey())) >= 1);
    check('kesiapan suksesi (fondasi Phase 3)', count($talent->successionReady((int) $headPosition->getKey())['candidates']) >= 1);

    $recognition = $talent->recognize($employee, ['award_code' => 'AWARD-EMPLOYEE', 'title' => 'Pegawai Teladan', 'message' => 'Kontribusi luar biasa', 'source' => 'MANAGER'], $hr);
    check('apresiasi tercatat', $recognition->getKey() !== null, $recognition->code);
    check('dasbor talenta', $talent->dashboard($school->getKey())['pool_members'] >= 1);
} catch (Throwable $exception) {
    check('alur karier & talenta', false, $exception->getMessage().' @ '.$exception->getFile().':'.$exception->getLine());
}

// ------------------------------------------------------------------ 9. ATURAN, PERINGATAN, LAYANAN, ANALITIK, AI
section('9. Mesin aturan, peringatan, layanan, analitik, dan AI');

try {
    $rules = app(RuleEngine::class);
    $eligibility = app(EligibilityEngine::class);

    check('aturan kelayakan benefit cocok', ($eligibility->forBenefit($employee)['eligible'] ?? false) === true, implode('; ', $eligibility->forBenefit($employee)['reasons']));
    check('penjelasan aturan tersedia', isset($rules->explain('BENEFIT_ELIGIBILITY', $employee)['rules']));
    check('aturan pelatihan wajib', ($rules->actions('TRAINING_REQUIREMENT', $employee)[0]['training_code'] ?? null) === 'CHILD-PROTECTION');

    $alerts = app(AlertService::class);
    $counts = $alerts->scanAll();
    check('pemindaian peringatan tanpa galat', is_array($counts), 'peringatan dibangkitkan: '.array_sum($counts));

    $ticket = app(HRServiceCenterService::class)->open($employee, ['category' => 'PAYROLL', 'subject' => 'Slip gaji belum diterima', 'priority' => 'HIGH'], $manager);
    $service = app(HRServiceCenterService::class);
    $service->comment($ticket, ['body' => 'Sedang kami periksa'], $hr);
    $service->resolve($ticket->refresh(), $hr, 'Slip dikirim ulang');
    check('tiket layanan HR selesai', $ticket->refresh()->status === 'RESOLVED', $ticket->code.' (SLA '.$ticket->sla_due_at?->format('d H:i').')');
    check('antrean layanan terbaca', isset($service->queue()['open']));

    $analytics = app(WorkforceAnalyticsService::class);
    $dashboard = $analytics->dashboard($school->getKey());
    check('dasbor analitik lengkap', isset($dashboard['headcount'], $dashboard['turnover'], $dashboard['attendance'], $dashboard['payroll'], $dashboard['performance'], $dashboard['learning'], $dashboard['talent'], $dashboard['workforce_cost']), 'pegawai aktif '.$dashboard['headcount']['active']);
    check('snapshot analitik tersimpan', count($analytics->snapshot($school->getKey())) === 7);

    $ai = app(AIHRService::class);
    $suggestion = $ai->suggest('SUMMARIZE_CANDIDATE', ['candidate' => 'Kandidat Guru Baru', 'salary' => 5500000, 'performance_rating' => 4], $hr, $employee);
    check('AI menolak kolom sensitif', ! array_key_exists('salary', $suggestion['suggestion'] ?? []) && ($suggestion['disclaimer'] ?? '') !== '', 'status '.$suggestion['status']);
    check('AI tidak pernah memutuskan', ($ai->capabilities()['may_decide_hiring'] ?? true) === false);
} catch (Throwable $exception) {
    check('alur tata kelola', false, $exception->getMessage().' @ '.$exception->getFile().':'.$exception->getLine());
}

print(PHP_EOL.'=== RINGKASAN UJI PHASE 2 ==='.PHP_EOL);
print('lulus: '.$pass.' | gagal: '.$fail.PHP_EOL);

exit($fail === 0 ? 0 : 1);
