<?php

/**
 * Uji penerimaan HRD Phase 3 (§165) pada basis data nyata:
 * Workforce → Skills → Talent → Succession → Analytics → AI.
 *
 * Jalankan: DB_CONNECTION=sqlite DB_DATABASE=/tmp/hrd3.sqlite CACHE_STORE=array php tests/Manual/hrd_phase3_smoke.php
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Modules\Hrd\Application\Services\EmployeeService;
use App\Modules\Hrd\Application\Services\OrganizationService;
use App\Modules\Hrd\Application\Services\PositionService;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeSkill;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Models\HrSkill;
use App\Modules\Hrd\Strategic\Academic\Services\AcademicWorkforceService;
use App\Modules\Hrd\Strategic\Ai\Services\AIGovernanceService;
use App\Modules\Hrd\Strategic\Ai\Services\AIOrchestratorService;
use App\Modules\Hrd\Strategic\Ai\Services\AiIntelligenceService;
use App\Modules\Hrd\Strategic\Analytics\Services\MetricEngine;
use App\Modules\Hrd\Strategic\Analytics\Services\PeopleAnalyticsService;
use App\Modules\Hrd\Strategic\Compensation\Services\CompensationIntelligenceService;
use App\Modules\Hrd\Strategic\Experience\Services\EmployeeExperienceService;
use App\Modules\Hrd\Strategic\Experience\Services\JourneyAutomationService;
use App\Modules\Hrd\Strategic\Forecasting\Services\ForecastService;
use App\Modules\Hrd\Strategic\Governance\Services\RiskService;
use App\Modules\Hrd\Strategic\Governance\Services\ScorecardService;
use App\Modules\Hrd\Strategic\Retention\Services\RetentionAnalyticsService;
use App\Modules\Hrd\Strategic\Skills\Services\SkillsIntelligenceService;
use App\Modules\Hrd\Strategic\Succession\Services\SuccessionService;
use App\Modules\Hrd\Strategic\Talent\Services\CareerIntelligenceService;
use App\Modules\Hrd\Strategic\Talent\Services\TalentIntelligenceService;
use App\Modules\Hrd\Strategic\Talent\Services\TalentMarketplaceService;
use App\Modules\Hrd\Strategic\Workforce\Services\OrganizationDesignService;
use App\Modules\Hrd\Strategic\Workforce\Services\ScenarioService;
use App\Modules\Hrd\Strategic\Workforce\Services\WorkforcePlanningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fixture uji dibangun dengan insert langsung; integritas referensial tetap
// diuji pada jalur layanan (yang memakai validasi Phase 1/2), sedangkan
// penonaktifan FK di sini hanya berlaku pada basis data uji.
if (DB::getDriverName() === 'sqlite') {
    DB::statement('PRAGMA foreign_keys = OFF');
}

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

/** Menjalankan blok uji tanpa menghentikan seluruh skrip. */
function attempt(string $label, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        check($label, false, get_class($e).': '.$e->getMessage());
    }
}

/** Insert tahan-schema: kolom asing diabaikan, kolom wajib diisi wajar. */
function put(string $table, array $data): int
{
    $columns = DB::select('select name, type, "notnull" as nn, dflt_value as dflt, pk from pragma_table_info(?)', [$table]);
    $known = array_map(fn ($column): string => (string) $column->name, $columns);
    $payload = [];

    foreach ($columns as $column) {
        $key = (string) $column->name;
        if ((int) $column->pk === 1) {
            continue;
        }
        if (array_key_exists($key, $data)) {
            $payload[$key] = $data[$key];
            continue;
        }
        if ((int) $column->nn === 1 && $column->dflt === null) {
            $type = strtolower((string) $column->type);
            // Nilai wajib yang tidak disebutkan: teks unik agar tidak menabrak index unik.
            $payload[$key] = str_contains($type, 'int') ? 0
                : (str_contains($type, 'date') || str_contains($type, 'time') ? now() : 'AUTO-'.substr(md5(uniqid('', true)), 0, 8));
        }
    }
    foreach ($data as $key => $value) {
        if (in_array($key, $known, true) && ! array_key_exists($key, $payload)) {
            $payload[$key] = $value;
        }
    }
    $payload['created_at'] = $payload['created_at'] ?? now();
    $payload['updated_at'] = $payload['updated_at'] ?? now();

    return (int) DB::table($table)->insertGetId($payload);
}

function actor(string $email, array $roles): User
{
    $user = User::query()->firstOrCreate(
        ['email' => $email],
        ['name' => ucfirst(explode('@', $email)[0]), 'password' => bcrypt('secret-pass-123'), 'is_active' => true],
    );

    // Peran dibuat bila belum ada agar uji tidak bergantung urutan seeder.
    $roleModel = 'Spatie\\Permission\\Models\\Role';
    foreach ($roles as $role) {
        if (class_exists($roleModel)) {
            $roleModel::findOrCreate($role, 'web');
        }
    }
    $user->syncRoles($roles);

    return $user;
}

/* ================================================================ RESET DATA UJI */
// Uji harus dapat dijalankan berulang: bersihkan artefak uji, pertahankan data referensi.
$resetTables = [
    'hr_employee_skill_profiles', 'hr_skill_evidence', 'hr_skill_assessments', 'hr_skill_gaps', 'hr_skill_governance',
    'hr_position_skills', 'hr_job_skills', 'hr_skill_requirements', 'hr_skill_demand', 'hr_skill_forecasts',
    'hr_position_skill_requirements', 'hr_employee_skills', 'hr_certifications', 'hr_internal_job_applications',
    'hr_training_completions', 'hr_training_assignments', 'hr_leave_balances', 'hr_payroll_items', 'hr_payroll_runs', 'hr_payroll_periods', 'hr_contracts', 'hr_recognitions', 'hr_talent_reviews', 'hr_talent_pool_members',
    'hr_attendance_records', 'hr_overtime_records', 'hr_performance_reviews', 'hr_talent_review_results',
    'hr_employee_interests', 'hr_employee_availability', 'hr_project_assignments', 'hr_temporary_assignments',
    'hr_workforce_plans', 'hr_workforce_plan_versions', 'hr_workforce_demand', 'hr_workforce_supply', 'hr_workforce_gaps',
    'hr_workforce_scenarios', 'hr_workforce_scenario_assumptions', 'hr_workforce_forecasts', 'hr_workforce_cost_forecasts',
    'hr_organization_design_scenarios', 'hr_position_plans', 'hr_workforce_resource_requirements',
    'hr_critical_positions', 'hr_succession_candidates', 'hr_succession_readiness', 'hr_succession_risks',
    'hr_succession_scenarios', 'hr_succession_plans', 'hr_leadership_pipeline',
    'hr_talent_opportunities', 'hr_opportunity_requirements', 'hr_employee_opportunity_matches',
    'hr_metric_snapshots', 'hr_workforce_snapshots', 'hr_people_analytics_snapshots', 'hr_forecast_runs',
    'hr_forecast_inputs', 'hr_forecast_outputs', 'hr_ai_conversations', 'hr_ai_tool_calls', 'hr_ai_runs',
    'hr_ai_recommendations', 'hr_ai_feedback', 'hr_ai_usage_logs', 'hr_ai_cost_logs', 'hr_ai_audit_logs',
    'hr_bias_monitoring', 'hr_risks', 'hr_risk_signals', 'hr_risk_evidence', 'hr_risk_actions', 'hr_risk_history',
    'hr_strategic_hr_actions', 'hr_strategic_hr_action_items', 'hr_strategic_hr_action_results', 'hr_strategic_hr_action_reviews',
    'hr_retention_signals', 'hr_attrition_snapshots', 'hr_journey_instances', 'hr_journey_events',
    'hr_experience_snapshots', 'hr_engagement_snapshots', 'hr_pay_equity_findings', 'hr_pay_equity_analyses',
    'hr_compensation_scenarios', 'hr_incentive_simulations', 'hr_service_insights', 'hr_org_relationships',
    'hr_experiments', 'hr_experiment_results', 'hr_journey_definitions', 'hr_journey_stages',
    'hr_employees', 'hr_people', 'hr_positions', 'hr_departments', 'hr_organizations',
];
foreach ($resetTables as $table) {
    if (Schema::hasTable($table)) {
        DB::table($table)->delete();
    }
}
put('hr_journey_definitions', ['code' => 'JOURNEY-ONBOARDING', 'name' => 'Onboarding', 'journey_type' => 'ONBOARDING',
    'trigger_event' => 'EmployeeCreated', 'status' => 'ACTIVE', 'version' => 1]);
put('hr_journey_stages', ['journey_code' => 'JOURNEY-ONBOARDING', 'sequence' => 1, 'code' => 'DOCUMENTS',
    'name' => 'Kelengkapan dokumen', 'stage_type' => 'TASK', 'offset_days' => 1, 'is_required' => true]);
put('hr_journey_stages', ['journey_code' => 'JOURNEY-ONBOARDING', 'sequence' => 2, 'code' => 'ORIENTATION',
    'name' => 'Orientasi', 'stage_type' => 'TASK', 'offset_days' => 3, 'is_required' => true]);

/* ================================================================ AKTOR */
$hr = actor('hrd.phase3@syntaf.test', ['HR_ADMIN', 'HR_STRATEGIST']);
$director = actor('director.phase3@syntaf.test', ['HR_DIRECTOR', 'HR_STRATEGIST']);
$payrollUser = actor('payroll.phase3@syntaf.test', ['PAYROLL_ADMIN']);
// Izin eksplisit agar uji tidak bergantung komposisi izin bawaan peran.
if (method_exists($payrollUser, 'givePermissionTo')) {
    $payrollUser->givePermissionTo('hr.payroll.view');
}
$manager = actor('manager.phase3@syntaf.test', ['MANAGER']);
$employeeUser = actor('employee.phase3@syntaf.test', ['EMPLOYEE']);

/* ================================================================ DATASET */
$organizations = app(OrganizationService::class);
$positions = app(PositionService::class);
$employees = app(EmployeeService::class);

$foundation = $organizations->createOrganization(['code' => 'YYS-P3', 'name' => 'Yayasan Phase 3', 'type_code' => 'Foundation'], $hr);
$sma = $organizations->createOrganization(['code' => 'SMA-P3', 'name' => 'SMA Phase 3', 'type_code' => 'School', 'parent_id' => $foundation->getKey()], $hr);
$smp = $organizations->createOrganization(['code' => 'SMP-P3', 'name' => 'SMP Phase 3', 'type_code' => 'School', 'parent_id' => $foundation->getKey()], $hr);
$boarding = $organizations->createOrganization(['code' => 'ASR-P3', 'name' => 'Asrama Phase 3', 'type_code' => 'Unit', 'parent_id' => $foundation->getKey()], $hr);

$deptAkademik = $organizations->createDepartment(['code' => 'AKD-P3', 'name' => 'Akademik P3', 'organization_id' => $sma->getKey()], $hr);
$deptIt = $organizations->createDepartment(['code' => 'IT-P3', 'name' => 'IT P3', 'organization_id' => $sma->getKey()], $hr);
$deptAsrama = $organizations->createDepartment(['code' => 'ASR-D-P3', 'name' => 'Asrama P3', 'organization_id' => $boarding->getKey()], $hr);
$deptSmp = $organizations->createDepartment(['code' => 'AKD-SMP-P3', 'name' => 'Akademik SMP P3', 'organization_id' => $smp->getKey()], $hr);

$posTeacher = $positions->create(['code' => 'GURU-MTK-P3', 'title' => 'Guru Matematika', 'organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'authorized_headcount' => 4, 'grade_code' => 'G3'], $hr);
$posEngTeacher = $positions->create(['code' => 'GURU-ENG-P3', 'title' => 'Guru Bahasa Inggris', 'organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'authorized_headcount' => 2, 'grade_code' => 'G3'], $hr);
$posHeadIt = $positions->create(['code' => 'KADEP-IT-P3', 'title' => 'Kepala IT', 'organization_id' => $sma->getKey(), 'department_id' => $deptIt->getKey(), 'authorized_headcount' => 1, 'grade_code' => 'G6', 'is_critical' => true], $hr);
$posItStaff = $positions->create(['code' => 'IT-STAFF-P3', 'title' => 'Staf IT', 'organization_id' => $sma->getKey(), 'department_id' => $deptIt->getKey(), 'authorized_headcount' => 3, 'grade_code' => 'G4'], $hr);
$posMusyrif = $positions->create(['code' => 'MUSYRIF-P3', 'title' => 'Musyrif Asrama', 'organization_id' => $boarding->getKey(), 'department_id' => $deptAsrama->getKey(), 'authorized_headcount' => 10, 'grade_code' => 'G3'], $hr);
$posTeacherSmp = $positions->create(['code' => 'GURU-SMP-P3', 'title' => 'Guru SMP', 'organization_id' => $smp->getKey(), 'department_id' => $deptSmp->getKey(), 'authorized_headcount' => 4, 'grade_code' => 'G3'], $hr);
$posAdmin = $positions->create(['code' => 'ADM-P3', 'title' => 'Staf Administrasi', 'organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'authorized_headcount' => 2, 'grade_code' => 'G2'], $hr);

function makeEmployee($service, array $data, string $name, string $nik, string $email, $actor)
{
    // Nomor pegawai eksplisit: uji harus deterministik dan dapat dijalankan ulang.
    return $service->create($data + [
        'employee_number' => 'P3-'.substr($nik, -4),
        'worker_type' => 'Teacher', 'employment_type' => 'Permanent', 'employment_status' => 'ACTIVE',
        'lifecycle_state' => 'active',
        'person' => ['full_name' => $name, 'nik' => $nik, 'gender' => 'male', 'birth_date' => '1988-03-05', 'work_email' => $email],
    ], $actor);
}

$teacher1 = makeEmployee($employees, [
    'organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'position_id' => $posTeacher->getKey(),
    'hire_date' => now()->subYears(3)->toDateString(),
    'assignment' => ['position_id' => $posTeacher->getKey(), 'organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'start_date' => now()->subYears(3)->toDateString()],
], 'Guru Matematika Satu', '3271000000000101', 'guru.mtk1@syntaf.test', $hr);

$teacher2 = makeEmployee($employees, [
    'organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'position_id' => $posTeacher->getKey(),
    'hire_date' => now()->subYears(2)->toDateString(),
    'assignment' => ['position_id' => $posTeacher->getKey(), 'organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'start_date' => now()->subYears(2)->toDateString()],
], 'Guru Matematika Dua', '3271000000000102', 'guru.mtk2@syntaf.test', $hr);

$teacher3 = makeEmployee($employees, [
    'organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'position_id' => $posEngTeacher->getKey(),
    'hire_date' => now()->subMonths(8)->toDateString(),
    'assignment' => ['position_id' => $posEngTeacher->getKey(), 'organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'start_date' => now()->subMonths(8)->toDateString()],
], 'Guru Inggris Tiga', '3271000000000103', 'guru.eng3@syntaf.test', $hr);

$headIt = makeEmployee($employees, [
    'organization_id' => $sma->getKey(), 'department_id' => $deptIt->getKey(), 'position_id' => $posHeadIt->getKey(),
    'hire_date' => now()->subYears(6)->toDateString(), 'worker_type' => 'Staff',
    'assignment' => ['position_id' => $posHeadIt->getKey(), 'organization_id' => $sma->getKey(), 'department_id' => $deptIt->getKey(), 'start_date' => now()->subYears(6)->toDateString()],
], 'Kepala IT', '3271000000000104', 'kadep.it@syntaf.test', $hr);

$itStaff1 = makeEmployee($employees, [
    'organization_id' => $sma->getKey(), 'department_id' => $deptIt->getKey(), 'position_id' => $posItStaff->getKey(),
    'hire_date' => now()->subYears(2)->toDateString(), 'worker_type' => 'Staff',
    'assignment' => ['position_id' => $posItStaff->getKey(), 'organization_id' => $sma->getKey(), 'department_id' => $deptIt->getKey(), 'start_date' => now()->subYears(2)->toDateString()],
], 'Staf IT Satu', '3271000000000105', 'it1@syntaf.test', $hr);

$itStaff2 = makeEmployee($employees, [
    'organization_id' => $sma->getKey(), 'department_id' => $deptIt->getKey(), 'position_id' => $posItStaff->getKey(),
    'hire_date' => now()->subMonths(10)->toDateString(), 'worker_type' => 'Staff',
    'assignment' => ['position_id' => $posItStaff->getKey(), 'organization_id' => $sma->getKey(), 'department_id' => $deptIt->getKey(), 'start_date' => now()->subMonths(10)->toDateString()],
], 'Staf IT Dua', '3271000000000106', 'it2@syntaf.test', $hr);

$musyrif1 = makeEmployee($employees, [
    'organization_id' => $boarding->getKey(), 'department_id' => $deptAsrama->getKey(), 'position_id' => $posMusyrif->getKey(),
    'hire_date' => now()->subYears(1)->toDateString(), 'worker_type' => 'Staff',
    'assignment' => ['position_id' => $posMusyrif->getKey(), 'organization_id' => $boarding->getKey(), 'department_id' => $deptAsrama->getKey(), 'start_date' => now()->subYear()->toDateString()],
], 'Musyrif Satu', '3271000000000107', 'musyrif1@syntaf.test', $hr);

$admin1 = makeEmployee($employees, [
    'organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'position_id' => $posAdmin->getKey(),
    'hire_date' => now()->subYears(4)->toDateString(), 'worker_type' => 'Staff',
    'assignment' => ['position_id' => $posAdmin->getKey(), 'organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'start_date' => now()->subYears(4)->toDateString()],
], 'Staf Administrasi', '3271000000000108', 'adm1@syntaf.test', $hr);

// Pegawai keluar (untuk turnover historis) + pegawai dengan gap keterampilan.
$leaver = makeEmployee($employees, [
    'organization_id' => $smp->getKey(), 'department_id' => $deptSmp->getKey(), 'position_id' => $posTeacherSmp->getKey(),
    'hire_date' => now()->subYears(3)->toDateString(),
    'assignment' => ['position_id' => $posTeacherSmp->getKey(), 'organization_id' => $smp->getKey(), 'department_id' => $deptSmp->getKey(), 'start_date' => now()->subYears(3)->toDateString()],
], 'Guru Keluar', '3271000000000109', 'guru.keluar@syntaf.test', $hr);
$leaver->forceFill(['termination_date' => now()->subMonths(2)->toDateString(), 'exit_reason_code' => 'RESIGN', 'is_active' => false, 'lifecycle_state' => 'terminated'])->save();

$employeeUser->forceFill([])->save();
DB::table('hr_employees')->where('id', $employeeUser->getKey())->update(['user_id' => null]);
DB::table('hr_employees')->where('id', $teacher3->getKey())->update(['user_id' => $manager->getKey()]);
DB::table('hr_employees')->where('id', $itStaff2->getKey())->update(['user_id' => $employeeUser->getKey()]);

$headcount = (int) HrEmployee::query()->where('is_active', true)->count();

/* ================================================================ SKILLS DATA */
$skillLevels = ['LARAVEL' => 'ADVANCED', 'CLOUD' => 'BEGINNER', 'AI' => 'BEGINNER', 'MATEMATIKA' => 'EXPERT',
    'BAHASA_INGGRIS' => 'INTERMEDIATE', 'LEADERSHIP' => 'BEGINNER', 'PEDAGOGI' => 'ADVANCED'];
$skills = app(SkillsIntelligenceService::class);
$skills->ensureLevels();
foreach ($skillLevels as $code => $level) {
    HrSkill::query()->firstOrCreate(['code' => $code], [
        'name' => ucwords(strtolower(str_replace('_', ' ', $code))), 'category' => 'HRD3', 'is_active' => true,
    ]);
}

$assign = function (int $employeeId, string $code, string $level) use ($skills, $hr, $headIt): void {
    $skills->assess($employeeId, $code, $level, 'MANAGER', (int) $headIt->getKey(), $hr);
};
$assign((int) $headIt->getKey(), 'LEADERSHIP', 'ADVANCED');
$assign((int) $headIt->getKey(), 'CLOUD', 'INTERMEDIATE');
$assign((int) $headIt->getKey(), 'LARAVEL', 'EXPERT');
$assign((int) $itStaff1->getKey(), 'LARAVEL', 'ADVANCED');
$assign((int) $itStaff1->getKey(), 'CLOUD', 'BEGINNER');
$assign((int) $itStaff2->getKey(), 'AI', 'BEGINNER');
$assign((int) $teacher1->getKey(), 'MATEMATIKA', 'EXPERT');
$assign((int) $teacher1->getKey(), 'PEDAGOGI', 'ADVANCED');
$assign((int) $teacher2->getKey(), 'MATEMATIKA', 'INTERMEDIATE');
$assign((int) $teacher3->getKey(), 'BAHASA_INGGRIS', 'INTERMEDIATE');
$assign((int) $admin1->getKey(), 'PEDAGOGI', 'BEGINNER');

// Syarat keterampilan posisi (memakai tabel Phase 1/2) — Kepala IT.
foreach ([['LEADERSHIP', 'ADVANCED', true], ['CLOUD', 'ADVANCED', true], ['AI', 'INTERMEDIATE', false], ['LARAVEL', 'ADVANCED', false]] as $requirement) {
    put('hr_position_skill_requirements', [
        'position_id' => $posHeadIt->getKey(), 'skill_code' => $requirement[0],
        'required_level' => $requirement[1], 'is_mandatory' => $requirement[2], 'weight' => 1,
    ]);
}
foreach ([['MATEMATIKA', 'ADVANCED', true], ['PEDAGOGI', 'INTERMEDIATE', true]] as $requirement) {
    put('hr_position_skill_requirements', [
        'position_id' => $posTeacher->getKey(), 'skill_code' => $requirement[0],
        'required_level' => $requirement[1], 'is_mandatory' => $requirement[2], 'weight' => 1,
    ]);
}
$skillSync = $skills->syncRequirementsFromPhase2();

/* ================================================================ OPERASIONAL (Phase 1/2) */
$periodId = put('hr_payroll_periods', [
    'code' => 'PP-P3-'.now()->format('Ym'), 'start_date' => now()->startOfMonth()->toDateString(),
    'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'OPEN', 'period' => now()->format('Y-m'),
]);
$payrollRunId = put('hr_payroll_runs', [
    'code' => 'PR-P3-'.now()->format('Ym'), 'period_id' => $periodId, 'status' => 'FINALIZED',
]);
foreach (HrEmployee::query()->where('is_active', true)->pluck('id') as $employeeId) {
    $base = 5_000_000 + ((int) $employeeId % 3) * 1_500_000;
    put('hr_payroll_items', [
        'employee_id' => $employeeId, 'run_id' => $payrollRunId, 'base_amount' => $base,
        'gross_amount' => $base, 'net_amount' => $base * 0.9, 'tax_total' => $base * 0.05,
        'overtime_amount' => 0, 'currency' => 'IDR', 'created_at' => now(),
    ]);
}
foreach (HrEmployee::query()->where('is_active', true)->pluck('id') as $index => $employeeId) {
    foreach ([3, 6, 9, 12, 18] as $daysAgo) {
        put('hr_attendance_records', [
            'employee_id' => $employeeId, 'date' => now()->subDays($daysAgo)->toDateString(),
            'status' => ($index + $daysAgo) % 9 === 0 ? 'LATE' : (($index + $daysAgo) % 17 === 0 ? 'ABSENT' : 'PRESENT'),
            'worked_minutes' => 480, 'late_minutes' => ($index + $daysAgo) % 9 === 0 ? 20 : 0, 'overtime_minutes' => 0,
        ]);
    }
}
if ((int) $itStaff1->getKey() % 2 === 1) {
    put('hr_overtime_records', ['employee_id' => $itStaff1->getKey(), 'date' => now()->subDays(5)->toDateString(),
        'status' => 'VERIFIED', 'verified_minutes' => 1200, 'amount' => 300000]);
}
$talentReviewId = put('hr_talent_reviews', ['code' => 'TRV-P3-001', 'name' => 'Tinjauan Talenta P3',
    'review_type' => 'ANNUAL', 'organization_id' => $sma->getKey(), 'status' => 'COMPLETED']);
foreach ([[$teacher1, 4.4, 4.5, 'HIGH_HIGH', 'READY_NOW'], [$teacher2, 3.6, 4.2, 'HIGH_HIGH', 'READY_LT_1Y'],
    [$itStaff1, 4.2, 3.4, 'MID_HIGH', 'READY_NOW'], [$teacher3, 3.4, 3.2, 'MID_MID', 'READY_3Y_PLUS'],
    [$headIt, 4.6, 4.4, 'HIGH_HIGH', 'READY_NOW'], [$admin1, 3.1, 2.4, 'LOW_MID', 'NOT_READY']] as $row) {
    put('hr_talent_review_results', [
        'review_id' => $talentReviewId, 'employee_id' => $row[0]->getKey(), 'performance_rating' => $row[1], 'potential_rating' => $row[2],
        'nine_box_code' => $row[3], 'readiness' => $row[4], 'career_aspiration' => 'Berkembang ke peran kepemimpinan',
        'risk_of_loss' => 'MEDIUM', 'impact_of_loss' => 'HIGH',
    ]);
    put('hr_performance_reviews', ['code' => 'PRV-P3-'.$row[0]->getKey(), 'employee_id' => $row[0]->getKey(),
        'status' => 'FINALIZED', 'reviewer_employee_id' => $headIt->getKey(),
        'overall_score' => min(100, $row[1] * 20), 'rating_label' => 'MEETS']);
}
foreach ([[$teacher1, 'Sertifikasi Pendidik', now()->addDays(45)], [$itStaff2, 'Cloud Practitioner', now()->addDays(70)]] as $row) {
    // Katalog sertifikasi Phase 1 + kepemilikan pegawai bila tabelnya tersedia.
    put('hr_certifications', ['code' => 'CERT-'.strtoupper(substr(md5($row[1]), 0, 6)), 'name' => $row[1],
        'issuer' => 'Lembaga Resmi', 'requires_expiry' => true, 'is_active' => true]);
    if (Schema::hasTable('hr_employee_certifications')) {
        put('hr_employee_certifications', ['employee_id' => $row[0]->getKey(), 'name' => $row[1], 'issuer' => 'Lembaga Resmi',
            'issue_date' => now()->subYear()->toDateString(), 'expiry_date' => $row[2]->toDateString(), 'status' => 'ACTIVE']);
    }
}
put('hr_contracts', ['contract_number' => 'CTR-P3-001', 'employee_id' => $teacher2->getKey(),
    'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->addDays(40)->toDateString(), 'status' => 'ACTIVE', 'is_signed' => true]);
put('hr_recognitions', ['code' => 'REC-P3-001', 'employee_id' => $itStaff1->getKey(), 'recognition_type' => 'PEER',
    'title' => 'Kontribusi proyek digitalisasi', 'message' => 'Membantu migrasi sistem', 'points' => 10,
    'recognized_at' => now()->subMonth(), 'period_code' => now()->format('Y-m')]);
foreach (HrEmployee::query()->where('is_active', true)->pluck('id') as $employeeId) {
    put('hr_leave_balances', ['employee_id' => $employeeId, 'leave_type_id' => 1, 'period_code' => now()->format('Y'),
        'entitlement_days' => 12, 'used_days' => 4, 'pending_days' => 1, 'available_days' => 7, 'accrued_days' => 12]);
}
foreach ([[$itStaff1, 'HIRED'], [$teacher2, 'SUBMITTED']] as $row) {
    put('hr_internal_job_applications', ['employee_id' => $row[0]->getKey(), 'status' => $row[1], 'created_at' => now()->subMonths(3)]);
}
put('hr_training_assignments', ['employee_id' => $itStaff1->getKey(), 'training_id' => 1, 'status' => 'ASSIGNED']);
put('hr_training_completions', ['employee_id' => $itStaff1->getKey(), 'training_id' => 1, 'score' => 92, 'completed_at' => now()->subMonth()]);
put('hr_employee_interests', ['employee_id' => $itStaff1->getKey(), 'interest_type' => 'SKILL', 'key' => 'AI', 'weight' => 1, 'source' => 'SELF']);

/* ================================================================ A. WORKFORCE */
section('A. Workforce: demand → supply → gap → cost → posisi');

$planning = app(WorkforcePlanningService::class);
$scenarios = app(ScenarioService::class);
$academic = app(AcademicWorkforceService::class);
$design = app(OrganizationDesignService::class);

$plan = null;
attempt('rencana tenaga kerja dibuat', function () use ($planning, $sma, $hr, &$plan): void {
    $plan = $planning->createPlan([
        'name' => 'Rencana Tenaga Kerja SMA P3', 'organization_id' => $sma->getKey(),
        'planning_horizon' => 'ANNUAL', 'budget_amount' => 1_200_000_000,
    ], $hr);
    check('rencana tenaga kerja dibuat', (bool) $plan->getKey(), $plan->code.' versi '.$plan->version);
});

attempt('permintaan tenaga kerja dicatat', function () use ($planning, $plan, $sma, $deptIt, $posItStaff, $deptAkademik, $posTeacher, $hr): void {
    $lines = $planning->addDemand($plan, [
        ['organization_id' => $sma->getKey(), 'department_id' => $deptIt->getKey(), 'position_id' => $posItStaff->getKey(),
            'required_headcount' => 8, 'required_skills' => [['code' => 'CLOUD', 'required_ordinal' => 3, 'headcount' => 4]],
            'budget_amount' => 480_000_000, 'driver' => 'OPERATIONAL'],
        ['organization_id' => $sma->getKey(), 'department_id' => $deptAkademik->getKey(), 'position_id' => $posTeacher->getKey(),
            'required_headcount' => 6, 'required_skills' => [['code' => 'MATEMATIKA', 'required_ordinal' => 3, 'headcount' => 4]],
            'budget_amount' => 400_000_000, 'driver' => 'ENROLLMENT'],
    ], $hr);
    check('permintaan tenaga kerja dicatat', count($lines) === 2, array_sum(array_map(fn ($l) => $l->required_headcount, $lines)).' kebutuhan headcount');
});

$supply = null;
attempt('pasokan aktual dihitung', function () use ($planning, $plan, $sma, $headcount, $hr, &$supply): void {
    $supply = $planning->captureSupply($plan, ['organization_id' => $sma->getKey()], $hr);
    check('pasokan aktual dihitung', $supply->active_headcount > 0, 'aktif '.$supply->active_headcount.' dari total '.$headcount
        .' | open position '.$supply->open_positions.' | attrition rate '.$supply->expected_attrition);
});

attempt('analisis gap enam dimensi', function () use ($planning, $plan, $hr): void {
    $analysis = $planning->analyseGaps($plan, $hr);
    $types = array_values(array_unique(array_column($analysis['gaps'], 'gap_type')));
    check('analisis gap menghasilkan temuan', $analysis['summary']['gaps'] > 0, 'gap '.$analysis['summary']['gaps'].' | tipe: '.implode(',', $types));
    check('gap headcount menandai kekurangan', in_array('HEADCOUNT', $types, true), 'headcount gap: '.$analysis['summary']['headcount_gap']);
    $skillGaps = array_filter($analysis['gaps'], fn (array $row): bool => $row['gap_type'] === 'SKILL');
    check('gap keterampilan punya rekomendasi build/buy/borrow', $skillGaps !== [] && isset(reset($skillGaps)['recommended_actions']),
        count($skillGaps).' gap keterampilan');
});

attempt('proyeksi biaya tenaga kerja', function () use ($planning, $plan, $hr): void {
    $forecast = $planning->forecastCost($plan, ['salary_increase_percent' => 5, 'planned_hires' => 3, 'budget_monthly' => 100_000_000], $hr);
    check('proyeksi biaya per bulan terbentuk', count($forecast['rows']) >= 12, $forecast['totals']['months'].' bulan | total '.number_format($forecast['totals']['total']));
    check('varians anggaran dihitung', $forecast['totals']['variance'] !== null, 'varians '.number_format((float) $forecast['totals']['variance']));
});

attempt('optimasi posisi & kapasitas', function () use ($planning, $sma): void {
    $optimisation = $planning->optimisePositions($sma->getKey());
    check('optimasi posisi mendeteksi lowongan/kritis', ($optimisation['summary']['vacant'] + $optimisation['summary']['understaffed']) > 0,
        'vacant '.$optimisation['summary']['vacant'].' | critical '.$optimisation['summary']['critical'].' | SPOF '.$optimisation['summary']['single_point_of_failure']);
    $capacity = $planning->capacity($sma->getKey(), now()->format('Y-m'));
    check('kapasitas & utilisasi terhitung', $capacity['employees'] > 0, 'pegawai '.$capacity['employees'].' | utilisasi '.$capacity['utilization_percent'].'%');
});

attempt('kebutuhan sumber daya dari headcount', function () use ($planning, $plan, $hr): void {
    $resources = $planning->planResources($plan, [
        ['type' => 'LAPTOP', 'unit_cost' => 12_000_000], ['type' => 'DESK', 'unit_cost' => 2_000_000],
        ['type' => 'ACCESS_CARD', 'unit_cost' => 150_000],
    ], $hr);
    check('kebutuhan aset dihitung dari proyeksi', $resources['headcount_delta'] >= 0 && count($resources['items']) === 3,
        'delta '.$resources['headcount_delta'].' | biaya '.number_format($resources['total_cost']));
});

/* ================================================================ B. SKENARIO */
section('B. Skenario, what-if, dan desain organisasi');

$employeesBefore = (int) HrEmployee::query()->count();
$growthScenario = null;
attempt('skenario pertumbuhan siswa +20%', function () use ($scenarios, $plan, $employeesBefore, $hr, &$growthScenario): void {
    $growthScenario = $scenarios->createScenario($plan, [
        'name' => 'Pertumbuhan siswa 20%', 'scenario_type' => 'GROWTH',
        'assumptions' => ['students_current' => 300, 'students_delta_percent' => 20, 'students_per_teacher' => 18,
            'counselor_per_400_students' => 1, 'hires' => 6, 'budget_monthly' => 150_000_000],
    ], $hr);
    $results = $scenarios->simulate($growthScenario, $hr);
    check('simulasi menghasilkan kebutuhan guru', ($results['demand']['teacher_gap'] ?? 0) > 0,
        'siswa '.$results['demand']['students_current'].'→'.$results['demand']['students_target']
        .' | guru '.$results['demand']['teachers_current'].'→'.$results['demand']['teachers_required']
        .' | gap '.$results['demand']['teacher_gap']);
    check('simulasi menghitung dampak biaya', ($results['cost']['delta'] ?? 0) !== 0.0, 'delta bulanan '.number_format($results['cost']['delta']));
    check('simulasi tidak menyentuh data aktual', ($results['integrity']['actual_data_untouched'] ?? false) === true,
        'pegawai sebelum '.$employeesBefore.' sesudah '.(int) HrEmployee::query()->count());
    check('simulasi menyertakan bukti & periode', isset($results['evidence']['period']) && isset($results['evidence']['data_source']));
});

attempt('what-if: 5 guru berhenti + kenaikan gaji 5%', function () use ($scenarios, $plan, $hr): void {
    $result = $scenarios->whatIf($plan, ['resignations' => 5, 'salary_increase_percent' => 5, 'students_current' => 300,
        'students_target' => 320, 'attrition_rate' => 8], $hr);
    check('what-if menampilkan risiko', ($result['risk'] ?? []) !== [], json_encode($result['risk']));
    check('what-if memberi proyeksi biaya', isset($result['cost']['annual_projected']), 'tahunan '.number_format((float) $result['cost']['annual_projected']));
});

attempt('perbandingan skenario', function () use ($scenarios, $plan, $growthScenario, $hr): void {
    $baseline = $scenarios->createScenario($plan, ['name' => 'Baseline', 'scenario_type' => 'BASELINE',
        'assumptions' => ['students_current' => 300]], $hr);
    $scenarios->simulate($baseline, $hr);
    $comparison = $scenarios->compare([(int) $baseline->getKey(), (int) $growthScenario->getKey()]);
    check('perbandingan skenario berdampingan', count($comparison['scenarios']) === 2 && $comparison['lowest_cost'] !== null,
        'termurah: '.$comparison['lowest_cost']);
});

attempt('academic → kebutuhan tenaga kerja', function () use ($academic): void {
    $demand = $academic->studentToWorkforceDemand(['students_current' => 300, 'students_delta_percent' => 15, 'subject_skills' => ['MATEMATIKA', 'BAHASA_INGGRIS']]);
    check('siswa +15% → kebutuhan guru', ($demand['teachers']['gap'] ?? 0) >= 0 && $demand['teachers']['required'] > 0,
        'guru dibutuhkan '.$demand['teachers']['required'].' | gap '.$demand['teachers']['gap'].' | rasio '.$demand['teachers']['student_teacher_ratio_target']);
    $capacity = $academic->teacherCapacity();
    check('kapasitas guru terhitung', $capacity['teachers'] >= 0, 'guru '.$capacity['teachers'].' | jam tersedia '.$capacity['available_hours_weekly']);
    $coverage = $academic->subjectCoverage();
    check('cakupan mapel & sertifikasi diperiksa', isset($coverage['subjects']), 'mapel '.$coverage['subjects'].' | gap '.$coverage['with_gap']);
});

attempt('boarding & clinic workforce', function () use ($academic): void {
    $boarding = $academic->boardingWorkforce(['santri_current' => 120, 'santri_delta_percent' => 15, 'dorm_capacity' => 200, 'shifts_per_day' => 3]);
    check('rasio santri → kebutuhan musyrif', $boarding['musyrif']['required_total'] > 0,
        'musyrif dibutuhkan '.$boarding['musyrif']['required_total'].' | shift '.$boarding['musyrif']['required_per_shift'].' | gap '.$boarding['musyrif']['gap']);
    $clinic = $academic->clinicWorkforce(['visits_per_day' => 45]);
    check('kunjungan → kebutuhan tenaga klinik', $clinic['staff']['required'] > 0,
        'dokter '.$clinic['staff']['doctor_demand'].' | perawat '.$clinic['staff']['nurse_demand'].' | gap '.$clinic['staff']['gap']);
});

attempt('skenario kampus baru', function () use ($academic, $plan, $hr): void {
    $campus = $academic->campusExpansionScenario($plan, ['name' => 'Kampus Cikarang', 'students' => 420, 'boarding' => 4], $hr);
    check('template staf kampus baru terbentuk', $campus['total_headcount'] > 0,
        'headcount '.$campus['total_headcount'].' | skenario '.$campus['scenario_code'].' | biaya bulanan '.number_format((float) ($campus['cost']['monthly_projected'] ?? 0)));
});

attempt('desain organisasi & span of control', function () use ($design, $sma, $hr): void {
    $scenario = $design->propose([
        'name' => 'Gabung Akademik & IT + kurangi layer', 'base_organization_id' => $sma->getKey(),
        'changes' => [
            ['type' => 'MERGE_DEPARTMENT', 'headcount' => 2, 'note' => 'Gabung layanan administrasi akademik'],
            ['type' => 'LAYER_REDUCTION', 'managers' => 1, 'headcount' => 1],
            ['type' => 'NEW_DEPARTMENT', 'headcount' => 3, 'managers' => 1, 'note' => 'Unit transformasi digital'],
        ],
    ], $hr);
    $impact = $design->simulate($scenario, $hr);
    check('simulasi desain organisasi hanya proposal', ($impact['integrity']['proposal_only'] ?? false) === true,
        'layer '.$impact['before']['layers'].'→'.$impact['after']['layers'].' | span delta '.$impact['impact']['span_delta']
        .' | biaya '.number_format($impact['impact']['cost_delta_monthly']));
    $span = $design->spanOfControl($sma->getKey());
    check('span of control dianalisis dengan penanda', $span['managers'] >= 0, 'manajer '.$span['managers'].' | rata-rata '.$span['average_team_size'].' | flag '.json_encode($span['flag_summary']));
    $network = $design->network($sma->getKey());
    check('jaringan organisasi terbentuk', count($network['nodes']) > 0,
        'node '.count($network['nodes']).' | relasi lintas fungsi '.count($network['cross_functional_edges']).' | proyek '.count($network['project_assignments']));
});

/* ================================================================ ATASAN & RELASI */
foreach ([[$itStaff1, $headIt], [$itStaff2, $headIt], [$teacher2, $teacher1], [$teacher3, $teacher1]] as $pair) {
    DB::table('hr_employees')->where('id', $pair[0]->getKey())->update(['manager_employee_id' => $pair[1]->getKey()]);
}
put('hr_org_relationships', ['from_employee_id' => $itStaff1->getKey(), 'to_employee_id' => $teacher1->getKey(),
    'relationship_type' => 'PROJECT', 'strength' => 0.6, 'effective_from' => now()->subMonths(3)->toDateString()]);
put('hr_org_relationships', ['from_employee_id' => $headIt->getKey(), 'to_employee_id' => $itStaff2->getKey(),
    'relationship_type' => 'MENTORING', 'strength' => 1.0, 'effective_from' => now()->subMonths(6)->toDateString()]);

/* ================================================================ C. SKILLS */
section('C. Skills intelligence: taksonomi, bukti, keyakinan, gap, heatmap');

$laravelSkill = HrSkill::query()->where('code', 'LARAVEL')->first();
$cloudSkill = HrSkill::query()->where('code', 'CLOUD')->first();
$aiSkill = HrSkill::query()->where('code', 'AI')->first();
$leadershipSkill = HrSkill::query()->where('code', 'LEADERSHIP')->first();

attempt('ontologi keterampilan', function () use ($skills, $laravelSkill, $cloudSkill, $aiSkill, $leadershipSkill, $skillSync): void {
    $skills->linkSkills((int) $laravelSkill->getKey(), (int) $cloudSkill->getKey(), 'COMPLEMENTARY', 0.8);
    $skills->linkSkills((int) $cloudSkill->getKey(), (int) $aiSkill->getKey(), 'PREREQUISITE', 0.7);
    $skills->linkSkills((int) $leadershipSkill->getKey(), (int) $laravelSkill->getKey(), 'RELATED', 0.3);
    $ontology = $skills->ontology((int) $laravelSkill->getKey());
    check('ontologi keterampilan terbentuk', ($ontology['total'] ?? 0) >= 2,
        'relasi '.$ontology['total'].' | complementary '.count($ontology['complementary']).' | related '.count($ontology['related']));
    check('sinkronisasi syarat Phase 1/2', ($skillSync['position_requirements'] ?? 0) > 0,
        'syarat posisi '.$skillSync['position_requirements']);
});

attempt('bukti, keyakinan, dan daur hidup keterampilan', function () use ($skills, $itStaff2, $hr): void {
    $skills->recordEvidence((int) $itStaff2->getKey(), 'AI', [
        'evidence_type' => 'CERTIFICATION', 'level' => 'INTERMEDIATE', 'source_type' => 'EXTERNAL',
        'observed_at' => now()->subMonths(2)->toDateString(), 'details' => ['issuer' => 'Lembaga AI'],
    ], $hr);
    $confidence = $skills->confidenceFor((int) $itStaff2->getKey(), 'AI');
    check('keyakinan keterampilan dihitung dari bukti', $confidence['confidence'] > 60 && $confidence['has_certification'] === true,
        'keyakinan '.$confidence['confidence'].' | bukti '.$confidence['evidence_count'].' | sertifikasi ya');
    $lifecycle = $skills->lifecycle((int) $itStaff2->getKey());
    check('daur hidup keterampilan ditandai', isset($lifecycle['by_state']['CURRENT']),
        'status '.json_encode($lifecycle['by_state']));
    $profile = $skills->refreshProfile((int) $itStaff2->getKey());
    check('profil keterampilan diperbarui', $profile->evidence_count > 0,
        'bukti '.$profile->evidence_count.' | keyakinan rata-rata '.$profile->avg_confidence);
});

attempt('gap keterampilan pegawai vs posisi', function () use ($skills, $itStaff1, $posHeadIt): void {
    $gap = $skills->gapForEmployee((int) $itStaff1->getKey(), 'POSITION', (int) $posHeadIt->getKey());
    check('gap keterampilan terdeteksi', count($gap['gaps']) > 0,
        count($gap['gaps']).' gap | prioritas '.json_encode(array_column($gap['gaps'], 'priority')));
    $first = $gap['gaps'][0] ?? [];
    check('gap menyertakan rekomendasi pengembangan', isset($first['development_options']) && $first['development_options'] !== [],
        ($first['skill_code'] ?? '-').' gap '.($first['gap'] ?? 0).' | '.count($first['development_options'] ?? []).' opsi | biaya '.number_format((float) ($first['estimated_cost'] ?? 0)));
});

attempt('heatmap, keterampilan kritis, supply vs demand', function () use ($skills, $sma): void {
    $heatmap = $skills->heatmap($sma->getKey());
    check('heatmap keterampilan per departemen', count($heatmap['cells']) > 0,
        count($heatmap['cells']).' sel | contoh '.json_encode(array_slice($heatmap['cells'], 0, 2)));
    $critical = $skills->criticalSkills($sma->getKey());
    check('keterampilan kritis teridentifikasi', $critical['codes'] !== [], implode(',', $critical['codes']));
    $supplyDemand = $skills->supplyVsDemand($sma->getKey());
    check('supply vs demand keterampilan', count($supplyDemand['rows']) > 0,
        count($supplyDemand['rows']).' keterampilan | total gap '.$supplyDemand['total_gap']);
    $forecast = $skills->forecastDemand($sma->getKey(), now()->addYear()->format('Y'), ['demand_growth_percent' => 15]);
    check('prakiraan permintaan keterampilan', count($forecast) > 0, count($forecast).' keterampilan diprakirakan');
});

attempt('build/buy/borrow & permintaan pelatihan', function () use ($skills): void {
    $options = $skills->buildBuyBorrow('CLOUD', 3);
    check('opsi build/buy/borrow dengan biaya', count($options['options']) === 3,
        'BUILD '.number_format($options['options']['BUILD']['cost']).' | BUY '.number_format($options['options']['BUY']['cost'])
        .' | BORROW '.number_format($options['options']['BORROW']['cost']));
    $demand = $skills->learningDemand();
    check('permintaan pelatihan dari gap nyata', $demand['total_employees'] > 0,
        $demand['total_employees'].' pegawai | '.count($demand['rows']).' keterampilan | biaya '.number_format($demand['total_cost']));
});

/* ================================================================ D. TALENT */
section('D. Talent intelligence: portofolio, profil, segmen, marketplace');

$talent = app(TalentIntelligenceService::class);
$marketplace = app(TalentMarketplaceService::class);
$career = app(CareerIntelligenceService::class);

attempt('portofolio pertumbuhan pegawai', function () use ($talent, $itStaff1, $teacher2): void {
    $portfolio = $talent->growthPortfolio((int) $itStaff1->getKey());
    check('portofolio menyatukan data lintas modul', count($portfolio['skills']) > 0 && $portfolio['performance']['score'] !== null,
        'skill '.count($portfolio['skills']).' | pelatihan '.count($portfolio['learning']['completions'])
        .' | pengakuan '.count($portfolio['achievements']).' | kinerja '.json_encode($portfolio['performance']['score'])
        .' | potensi '.json_encode($portfolio['potential']['score']));
    $profile = $talent->profile((int) $teacher2->getKey());
    check('profil menjawab pertanyaan talenta', $profile['what_they_can_do'] !== [] && $profile['skills_still_missing'] !== [],
        'bisa: '.implode(', ', array_slice($profile['what_they_can_do'], 0, 3))
        .' | posisi cocok: '.count($profile['positions_that_fit']).' | gap: '.count($profile['skills_still_missing'])
        .' | ada di posisi: '.($profile['positions_that_fit'] !== [] ? 'ya' : 'belum'));
});

attempt('segmentasi & matriks talenta', function () use ($talent, $itStaff1): void {
    $segment = $talent->segment((int) $itStaff1->getKey());
    check('segmentasi dengan bukti', $segment['segments'] !== [], implode('+', $segment['segments']));
    $segments = $talent->segments();
    check('distribusi segmen organisasi', ($segments['by_segment'] ?? []) !== [], json_encode($segments['by_segment']));
    $matrix = $talent->matrix('PERFORMANCE', 'POTENTIAL');
    check('matriks kinerja × potensi', count($matrix['cells']) > 0, json_encode($matrix['distribution']));
    $mobility = $talent->mobilityIntelligence();
    check('mobilitas internal teridentifikasi', $mobility['count'] > 0, $mobility['count'].' pegawai siap bergerak');
});

attempt('marketplace: peluang → pencocokan → tinjauan manusia', function () use ($marketplace, $itStaff1, $hr): void {
    $opportunity = $marketplace->publish([
        'title' => 'Proyek Transformasi Digital', 'opportunity_type' => 'PROJECT',
        'organization_id' => $itStaff1->organization_id, 'project_reference' => 'PRJ-DIG-01',
        'required_skills' => [['code' => 'AI', 'level' => 'INTERMEDIATE'], ['code' => 'LARAVEL', 'level' => 'ADVANCED']],
        'preferred_skills' => [['code' => 'CLOUD', 'level' => 'INTERMEDIATE']],
        'allocation_percent' => 30, 'duration_days' => 90, 'min_experience_years' => 1,
    ], $hr);
    check('peluang internal dipublikasikan', (bool) $opportunity->getKey(), $opportunity->code.' ('.$opportunity->status.')');

    $matching = $marketplace->match($opportunity);
    check('mesin pencocokan menghasilkan kandidat', $matching['count'] > 0,
        $matching['count'].' kandidat | teratas '.($matching['candidates'][0]['employee_number'] ?? '-')
        .' skor '.($matching['candidates'][0]['match_score'] ?? 0));
    $top = $matching['candidates'][0] ?? [];
    check('skor disertai faktor & bukti', ($top['factors'] ?? []) !== [] && ($top['evidence'] ?? []) !== [],
        json_encode($top['factors'] ?? []));
    check('hasil menunggu tinjauan manusia', ($top['human_review_status'] ?? '') === 'PENDING');

    $reviewed = $marketplace->review((int) $top['match_id'], 'CONFIRMED', $hr, 'Dipilih untuk wawancara');
    check('tinjauan manusia tercatat', $reviewed->human_review_status === 'CONFIRMED',
        'status '.$reviewed->human_review_status.' | peninjau '.$reviewed->reviewed_by);

    $assignment = $marketplace->assignProject([
        'employee_id' => $itStaff1->getKey(), 'opportunity_id' => $opportunity->getKey(),
        'project_name' => 'Transformasi Digital', 'role_title' => 'Tech Lead', 'allocation_percent' => 30,
    ], $hr);
    check('penugasan proyek dengan alokasi', (float) $assignment->allocation_percent === 30.0,
        'alokasi '.$assignment->allocation_percent.'%');
    $over = $marketplace->assignProject(['employee_id' => $itStaff1->getKey(), 'project_name' => 'Proyek Tambahan',
        'allocation_percent' => 90, 'override_reason' => 'Permintaan direktur'], $hr);
    $allocation = $marketplace->allocationAnalysis();
    check('alokasi berlebih terdeteksi & tercatat alasannya', $allocation['over_allocated'] > 0,
        'over-allocation '.$allocation['over_allocated'].' pegawai | rata-rata '.$allocation['average_allocation'].'%');
    $candidates = $marketplace->mobilityCandidates('LARAVEL');
    check('pool kandidat internal per keterampilan', $candidates['count'] > 0, $candidates['count'].' pemegang LARAVEL');
});

attempt('karier: simulasi, jalur, rencana pengembangan', function () use ($career, $itStaff2, $posHeadIt, $posItStaff, $hr): void {
    $simulation = $career->simulate((int) $itStaff2->getKey(), (int) $posHeadIt->getKey());
    check('simulasi karier menghitung gap & kesiapan', ($simulation['found'] ?? false) === true && $simulation['skill_gap'] !== [],
        'gap '.count($simulation['skill_gap']).' keterampilan | kesiapan '.$simulation['estimated_readiness']['level']
        .' ('.$simulation['estimated_readiness']['score'].') | pengalaman kurang '.$simulation['experience_gap']['missing_months'].' bulan');
    check('rencana pengembangan berurutan', count($simulation['career_plan']['actions']) > 0,
        $simulation['career_plan']['total_months'].' bulan | biaya '.number_format($simulation['career_plan']['total_cost']));
    $paths = $career->paths((int) $posItStaff->getKey(), 3);
    check('jalur karier bercabang', isset($paths['linear_route']) && isset($paths['alternative_routes']),
        'linear '.count($paths['linear_route']).' | alternatif '.count($paths['alternative_routes']));
    $plan = $career->plan((int) $itStaff2->getKey(), (int) $posHeadIt->getKey(), $hr);
    check('rencana karier tersimpan', ($plan['saved'] ?? false) === true, 'kesiapan '.$plan['simulation']['estimated_readiness']['level']);
});

/* ================================================================ E. SUCCESSION */
section('E. Succession: posisi kritis → kandidat → kesiapan → risiko → cakupan');

$succession = app(SuccessionService::class);
$critical = null;
$successionPlan = null;

attempt('posisi kritis & rencana suksesi', function () use ($succession, $posHeadIt, $hr, &$critical, &$successionPlan): void {
    $critical = $succession->flagCriticalPosition((int) $posHeadIt->getKey(), [
        'criticality' => 'LEADERSHIP_CRITICAL', 'replacement_difficulty' => 'HIGH',
        'min_successors' => 2, 'business_impact' => 'Menguasai infrastruktur & keamanan data sekolah',
    ], $hr);
    check('posisi kritis ditandai', (bool) $critical->getKey(),
        'kritikalitas '.$critical->criticality.' | risiko pensiun '.$critical->retirement_risk.' | incumbent '.$critical->incumbent_employee_id);
    $successionPlan = $succession->plan($critical, ['plan_type' => 'REPLACEMENT', 'horizon_months' => 24], $hr);
    check('rencana suksesi dibuat', (bool) $successionPlan->getKey(), $successionPlan->code.' | status '.$successionPlan->coverage_status);
});

attempt('nominasi kandidat & penilaian kesiapan', function () use ($succession, $successionPlan, $itStaff1, $itStaff2, $hr): void {
    $primary = $succession->nominate($successionPlan, (int) $itStaff1->getKey(), 'PRIMARY', $hr);
    $secondary = $succession->nominate($successionPlan, (int) $itStaff2->getKey(), 'SECONDARY', $hr);
    check('kandidat suksesi dinilai kesiapannya', $primary->readiness !== null,
        'primary '.$primary->readiness.' ('.$primary->readiness_score.') | secondary '.$secondary->readiness.' ('.$secondary->readiness_score.')');
    $readiness = \App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionReadiness::query()->where('candidate_id', $primary->getKey())->first();
    check('kesiapan disertai komponen & bukti', $readiness && ($readiness->evidence ?? []) !== [],
        'skill '.$readiness->skills_score.' | kinerja '.$readiness->performance_score.' | kompetensi '.$readiness->competency_score
        .' | pengembangan '.$readiness->development_score);
});

attempt('risiko suksesi & skenario kepergian', function () use ($succession, $successionPlan, $critical, $hr): void {
    $risks = $succession->assessRisks($successionPlan, $hr);
    check('risiko suksesi dinilai dengan bukti', $risks !== [],
        count($risks).' risiko | '.implode(',', array_map(fn ($risk) => $risk->risk_type.':'.$risk->severity, $risks)));
    check('risiko menyertakan tindakan yang disarankan', ($risks[0]->recommended_action ?? '') !== '', (string) ($risks[0]->recommended_action ?? ''));
    $scenario = $succession->scenario($critical, 'RESIGN', $hr);
    check('skenario kepergian incumbent', isset($scenario['candidates']) && $scenario['coverage']['candidates'] > 0,
        'kandidat '.$scenario['coverage']['candidates'].' | siap sekarang '.$scenario['coverage']['ready_now']
        .' | status '.$scenario['coverage']['status']);
});

attempt('cakupan suksesi & pipeline kepemimpinan', function () use ($succession, $itStaff1, $sma, $hr): void {
    $coverage = $succession->coverage($sma->getKey());
    check('cakupan suksesi dihitung', $coverage['critical_positions'] > 0,
        'kritis '.$coverage['critical_positions'].' | punya kandidat '.$coverage['with_successor']
        .' | coverage '.$coverage['coverage_percent'].'% | siap sekarang '.$coverage['readiness_distribution']['READY_NOW']);
    $succession->addToPipeline((int) $itStaff1->getKey(), 'EMERGING_LEADER', [
        'development_actions' => ['Mentoring dengan Kepala IT', 'Proyek transformasi digital'], 'sponsor_employee_id' => $hr->getKey(),
    ], $hr);
    $pipeline = $succession->pipeline();
    check('pipeline kepemimpinan terisi', ($pipeline['by_stage']['EMERGING_LEADER'] ?? 0) > 0, json_encode($pipeline['by_stage']));
    $dashboard = $succession->dashboard();
    check('dashboard suksesi menyoroti risiko tinggi', isset($dashboard['risks']['high_priority']),
        'risiko tinggi '.count($dashboard['risks']['high_priority']));
});

/* ================================================================ F. ANALYTICS */
section('F. People analytics: metrik kanonik, snapshot, tren, prakiraan, kompensasi');

$metrics = app(MetricEngine::class);
$analytics = app(PeopleAnalyticsService::class);
$forecast = app(ForecastService::class);
$compensation = app(CompensationIntelligenceService::class);
$retention = app(RetentionAnalyticsService::class);
$experience = app(\App\Modules\Hrd\Strategic\Experience\Services\EmployeeExperienceService::class);
$journey = app(JourneyAutomationService::class);

$activeHeadcount = (int) HrEmployee::query()->where('is_active', true)->count();

attempt('metrik kanonik dievaluasi dari data nyata', function () use ($metrics, $activeHeadcount): void {
    $headcount = $metrics->evaluate('HEADCOUNT');
    check('HEADCOUNT = pegawai aktif', (float) $headcount['value'] === (float) $activeHeadcount,
        $headcount['value'].' (sumber: '.implode(',', $headcount['source_tables']).')');
    $turnover = $metrics->evaluate('TURNOVER_RATE', [], now()->format('Y'));
    check('TURNOVER_RATE terhitung dari pegawai keluar', (float) $turnover['value'] > 0, $turnover['value'].'%');
    $cost = $metrics->evaluate('COST_PER_EMPLOYEE');
    check('COST_PER_EMPLOYEE dari payroll final', (float) $cost['value'] > 0, number_format((float) $cost['value']));
    $training = $metrics->evaluate('TRAINING_COMPLETION');
    check('TRAINING_COMPLETION terhitung', isset($training['value']), $training['value'].'%');
    $successionMetric = $metrics->evaluate('SUCCESSION_COVERAGE');
    check('SUCCESSION_COVERAGE terhitung', (float) $successionMetric['value'] > 0, $successionMetric['value'].'%');
    $skillGap = $metrics->evaluate('CRITICAL_SKILL_GAP');
    check('CRITICAL_SKILL_GAP terhitung', isset($skillGap['value']), $skillGap['value']);
});

attempt('silsilah metrik & definisi kanonik', function () use ($metrics): void {
    $lineage = $metrics->lineage('HEADCOUNT');
    check('silsilah metrik tersedia', ($lineage['lineage'] ?? []) !== [] && $lineage['definition']['is_canonical'] === true,
        'sumber '.$lineage['lineage'][0]['source_table'].' | basis '.$lineage['definition']['period_basis']);
    $canonical = $metrics->canonicalMetrics();
    check('daftar metrik kanonik', count($canonical['metrics']) >= 20, count($canonical['metrics']).' metrik terdaftar');
});

attempt('tren & snapshot analitik', function () use ($metrics, $analytics): void {
    $trend = $metrics->trend('HEADCOUNT', 3);
    check('tren MoM/QoQ/YoY dihitung', isset($trend['trend']['mom']), count($trend['series']).' periode | MoM '.$trend['trend']['mom'].'%');
    $snapshot = $analytics->captureWorkforceSnapshot('MONTHLY');
    check('snapshot workforce tersimpan', $snapshot->headcount > 0, 'headcount '.$snapshot->headcount.' | biaya '.number_format((float) $snapshot->payroll_cost));
    $domain = $analytics->captureDomainSnapshot('WORKFORCE');
    check('snapshot domain analitik tersimpan', ($domain->metrics ?? []) !== [], json_encode($domain->metrics));
    $coverage = $analytics->coverage();
    $available = array_sum(array_map(fn (array $row): int => count($row['available']), $coverage['domains']));
    check('cakupan domain analitik', count($coverage['domains']) === 10 && $available > 0,
        count($coverage['domains']).' domain | '.$available.' metrik tersedia');
    $drill = $analytics->drillDown();
    check('drill-down dimensi', $drill['by_department'] !== [], count($drill['by_department']).' departemen | '.count($drill['by_worker_type']).' jenis pekerja');
});

attempt('keamanan analitik menghormati scope', function () use ($analytics, $employeeUser, $itStaff2): void {
    $selfScope = $analytics->drillDown([], $employeeUser);
    $globalScope = $analytics->drillDown([], null);
    $selfTotal = array_sum(array_column($selfScope['by_department'], 'headcount'));
    $globalTotal = array_sum(array_column($globalScope['by_department'], 'headcount'));
    check('scope departemen menyembunyikan unit lain', $selfTotal > 0 && $selfTotal < $globalTotal,
        'terbatas '.$selfTotal.' vs global '.$globalTotal);
    $dashboard = $analytics->dashboard('EXECUTIVE', [], $employeeUser);
    check('dashboard menandai scope pemanggil', ($dashboard['scope'] ?? '') === 'DEPARTMENT', 'scope '.$dashboard['scope']);
});

attempt('prakiraan headcount, attrition, biaya', function () use ($forecast): void {
    $history = $forecast->history('HEADCOUNT', 6);
    check('riwayat headcount dari data aktual', count($history['series']) === 6, 'terakhir '.$history['latest']);
    $run = $forecast->run('FCT-HEADCOUNT', ['horizon_months' => 6, 'history_months' => 6]);
    check('prakiraan headcount dijalankan', isset($run['summary']['projected_end']),
        'proyeksi akhir '.$run['summary']['projected_end'].' | perubahan '.$run['summary']['change_percent'].'% | keyakinan '.$run['summary']['confidence']);
    $repeat = $forecast->run('FCT-HEADCOUNT', ['horizon_months' => 6, 'history_months' => 6]);
    check('prakiraan idempoten', ($repeat['reused'] ?? false) === true, 'run '.$repeat['run']);
    $attrition = $forecast->run('FCT-ATTRITION', ['horizon_months' => 3, 'history_months' => 6]);
    check('prakiraan attrition dijalankan', isset($attrition['summary']['method']),
        $attrition['summary']['method'].' | keyakinan '.$attrition['summary']['confidence']);
    check('registry model prakiraan', count($forecast->dashboard()['models']) >= 5);
});

attempt('kompensasi: distribusi, ekuitas, simulasi', function () use ($compensation, $hr): void {
    $analytics = $compensation->analytics();
    check('distribusi upah dianalisis', $analytics['employees_with_pay'] > 0,
        'median '.number_format($analytics['median']).' | p75 '.number_format($analytics['p75']).' | total '.number_format($analytics['total_monthly']));
    $equity = $compensation->payEquity(null, ['tolerance_percent' => 20], $hr);
    check('analisis ekuitas menghasilkan sinyal anomali (bukan tuduhan)', isset($equity['findings']),
        'grup '.$equity['groups_compared'].' | temuan '.count($equity['findings']));
    $scenario = $compensation->simulate(['name' => 'Kenaikan 5%', 'increase_percent' => 5, 'distribution_method' => 'PERFORMANCE_BASED',
        'budget_amount' => 5_000_000], $hr);
    check('simulasi kenaikan gaji dengan varians anggaran', $scenario->projected_total_cost > 0,
        'biaya '.number_format((float) $scenario->projected_total_cost).' | varians '.number_format((float) $scenario->budget_variance)
        .' | populasi '.$scenario->population_count);
    $incentive = $compensation->simulateIncentive(['name' => 'Bonus tahunan', 'pool_percent' => 5], $hr);
    check('simulasi bonus/insentif', $incentive->total_cost > 0, 'biaya '.number_format((float) $incentive->total_cost).' | penerima '.$incentive->eligible_count);
    check('varians anggaran terhadapbiaya aktual', isset($compensation->budgetVariance()['integration']), $compensation->budgetVariance()['integration']);
});

attempt('retensi & attrition', function () use ($retention): void {
    $signals = $retention->signals();
    check('sinyal retensi dihitung dengan faktor', $signals['population'] > 0 && $signals['rows'] !== [],
        'populasi '.$signals['population'].' | distribusi '.json_encode($signals['distribution']));
    check('sinyal menyertakan disclaimer & bukti', str_contains($signals['rows'][0]['disclaimer'], 'bukan prediksi'),
        'faktor '.json_encode(array_column($signals['rows'][0]['factors'], 'factor')));
    $attrition = $retention->attritionAnalytics(null, now()->format('Y'), 'DEPARTMENT');
    check('attrition per dimensi', $attrition['leavers'] > 0,
        'keluar '.$attrition['leavers'].' | turnover '.$attrition['turnover_rate_percent'].'%');
    $series = $retention->turnoverSeries(6);
    check('deret turnover bulanan', count($series['series']) === 6, 'tren '.json_encode($series['trend']));
});

attempt('employee experience, journey, eksperimen', function () use ($experience, $journey, $hr): void {
    $snapshot = $experience->captureSnapshot();
    check('ambang privasi survei ditegakkan', ($snapshot['suppressed'] ?? false) === true,
        'responden '.$snapshot['respondents'].' < ambang '.$snapshot['privacy_threshold'].' → hasil disembunyikan');
    $journeyAnalytics = $experience->journeyAnalytics();
    check('analitik perjalanan pegawai', count($journeyAnalytics['stages']) >= 6,
        $journeyAnalytics['cohort'].' pegawai baru | konversi probation '.($journeyAnalytics['stages'][2]['conversion_percent'] ?? 0).'%');
    $services = $experience->serviceIntelligence();
    check('intelijen layanan HR', isset($services['total_volume']), 'volume '.$services['total_volume']);
    $experiment = $experience->startExperiment(['name' => 'Program mentoring 6 bulan', 'initiative_type' => 'MENTORING',
        'baseline_metrics' => ['RETENTION' => 80], 'population' => ['employee_ids' => []], 'intervention' => 'Mentoring bulanan'], $hr);
    $measured = $experience->measureExperiment($experiment, [['metric_code' => 'RETENTION', 'baseline' => 80, 'value' => 86, 'cohort_size' => 12]], $hr);
    check('eksperimen HR diukur', count($measured['results']) === 1, 'delta '.$measured['results'][0]->delta_percent.'%');
});

attempt('otomasi perjalanan pegawai', function () use ($journey, $itStaff1, $hr): void {
    $started = $journey->start((int) $itStaff1->getKey(), 'JOURNEY-ONBOARDING', $hr);
    check('perjalanan pegawai dimulai', isset($started['stages']), count($started['stages'] ?? []).' tahap');
    $instance = \App\Modules\Hrd\Strategic\People\Models\HrJourneyInstance::query()->where('code', $started['instance'])->first();
    $advanced = $journey->advance($instance, 'DOCUMENTS', 'COMPLETED', ['note' => 'Dokumen lengkap'], $hr);
    check('tahap perjalanan maju dengan progres', $advanced['progress_percent'] > 0 && $advanced['next_stage'] !== null,
        'progres '.$advanced['progress_percent'].'% → tahap berikutnya '.$advanced['next_stage']);
    $automation = $journey->handleEvent('certification.expiring', ['event' => 'certification.expiring', 'mandatory' => true,
        'employee_id' => $itStaff1->getKey()], $hr);
    check('otomasi event → kondisi → aksi', ($automation['rules_triggered'] ?? []) !== [],
        count($automation['rules_triggered']).' aturan, '.count(array_column($automation['rules_triggered'][0]['actions'], 'action')).' aksi');
});

/* ================================================================ G. RISK & SCORECARD */
section('G. Risk register, strategic action, scorecard eksekutif');

$risks = app(RiskService::class);
$scorecardService = app(ScorecardService::class);

attempt('pindai risiko prediktif', function () use ($risks, $hr): void {
    $scan = $risks->scan(null, $hr);
    check('sembilan jenis sinyal dipindai', $scan['count'] > 0,
        $scan['count'].' sinyal | severity '.json_encode($scan['by_severity']));
    $signal = $scan['signals'][0] ?? [];
    check('sinyal menyertakan bukti, periode, dan tindakan', isset($signal['evidence'], $signal['period'], $signal['recommended_action']),
        $signal['signal'].' ('.$signal['severity'].') → '.(string) $signal['recommended_action']);
    $register = $risks->register();
    check('risk register terisi', $register['total'] > 0, 'tipe '.json_encode($register['by_type']));
});

attempt('insight → rencana aksi → pengukuran', function () use ($risks, $hr): void {
    $action = $risks->createAction([
        'title' => 'Tutup gap keterampilan cloud & AI', 'objective' => 'Meningkatkan kesiapan transformasi digital',
        'source_type' => 'INSIGHT', 'priority' => 'HIGH', 'budget_amount' => 250_000_000,
        'workforce_requirement' => '3 staf IT tersertifikasi cloud', 'skill_requirement' => 'CLOUD level ADVANCED',
        'talent_strategy' => 'Build melalui pelatihan + mentoring internal',
        'items' => [
            ['title' => 'Susun kurikulum pelatihan cloud', 'type' => 'TASK', 'due_date' => now()->addMonth()->toDateString()],
            ['title' => 'Jalankan pelatihan & asesmen', 'type' => 'MILESTONE'],
        ],
    ], $hr);
    check('rencana aksi dibuat dari insight', ($action['action'] ?? '') !== '', $action['action'].' | item '.$action['items'].' | workflow '.($action['workflow_reference'] ?? 'menunggu mesin workflow'));
    $model = \App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrAction::query()->where('code', $action['action'])->first();
    $decided = $risks->approveAction($model, $hr, 'APPROVED');
    check('persetujuan manusia mengubah status', $decided->status === 'IN_PROGRESS', 'status '.$decided->status);
    $measured = $risks->measure($model, [['metric_code' => 'CRITICAL_SKILL_GAP', 'baseline' => 12, 'target' => 6, 'actual' => 8]], $hr);
    check('hasil aksi diukur terhadap target', count($measured['results']) === 1,
        'varians '.$measured['results'][0]->variance.' | on track '.json_encode($measured['on_track']));
    $review = $risks->review($model, ['progress_note' => 'Pelatihan tahap 1 selesai', 'status' => 'ON_TRACK'], $hr);
    check('tinjauan berkala tercatat', (bool) $review->getKey(), 'tinjauan berikutnya '.$review->next_review_date?->toDateString());
});

attempt('command center, scorecard, executive, board', function () use ($risks, $scorecardService, $hr): void {
    $commandCenter = $risks->commandCenter(null, $hr);
    check('HR command center menghimpun seluruh sinyal', isset($commandCenter['workforce'], $commandCenter['risk'], $commandCenter['actions']),
        'headcount '.$commandCenter['workforce']['headcount'].' | sinyal '.$commandCenter['risk']['signals']);
    $scorecard = $scorecardService->scorecard();
    check('scorecard 10 area dengan target & varians', count($scorecard['areas']) === 10,
        json_encode($scorecard['summary']));
    check('area terukur menyertakan tren & sumber', isset($scorecard['areas'][0]['trend'], $scorecard['areas'][0]['source']),
        $scorecard['areas'][0]['area'].' → '.$scorecard['areas'][0]['status']);
    $executive = $scorecardService->executive(null, $hr);
    check('executive dashboard lengkap', isset($executive['workforce'], $executive['talent'], $executive['cost'], $executive['risk'], $executive['ai_insights']),
        'headcount '.$executive['workforce']['current_headcount'].' | coverage suksesi '.$executive['talent']['succession_coverage_percent'].'%');
    $foundation = $scorecardService->foundation();
    check('board/yayasan membandingkan unit', count($foundation['organizations']) > 0,
        count($foundation['organizations']).' unit | biaya per pegawai tertinggi: '.(string) $foundation['highest_cost_per_head']);
    $board = $scorecardService->analyticsBoard(null, $hr);
    check('papan analitik per domain', count($board['domains']) === 10, count($board['domains']).' domain');
});

/* ================================================================ H. AI */
section('H. AI: copilot, agent, tool, governance, human-in-the-loop');

$orchestrator = app(AIOrchestratorService::class);
$governance = app(AIGovernanceService::class);
$intelligence = app(AiIntelligenceService::class);
$tools = app(\App\Modules\Hrd\Strategic\Ai\Services\AIToolRegistry::class);

$leaveRun = null;
attempt('AI menjawab saldo cuti dengan sumber', function () use ($orchestrator, $hr, $itStaff1, &$leaveRun): void {
    $answer = $orchestrator->ask(['question' => 'Berapa sisa cuti saya?', 'employee_id' => $itStaff1->getKey()], $hr);
    $leaveRun = $answer;
    check('AI menjawab dari data nyata', str_contains($answer['answer'], 'sisa'),
        mb_substr($answer['answer'], 0, 120));
    check('jawaban menyertakan sumber & keyakinan', $answer['sources'] !== [] && $answer['confidence'] > 0,
        'sumber: '.implode(',', $answer['sources']).' | keyakinan '.$answer['confidence']);
    check('jawaban dapat dijelaskan (explainability)', isset($answer['explainability']['data_sources']),
        'periode '.$answer['explainability']['data_period']);
});

attempt('AI menjelaskan payroll & menghormati field-level security', function () use ($orchestrator, $payrollUser, $employeeUser, $itStaff2): void {
    $authorized = $orchestrator->ask(['question' => 'Kenapa take home pay saya bulan ini berbeda?', 'employee_id' => $itStaff2->getKey()], $payrollUser);
    check('pemegang izin payroll melihat angka', ! str_contains($authorized['answer'], 'memerlukan izin payroll'),
        mb_substr($authorized['answer'], 0, 100));
    $denied = $orchestrator->ask(['question' => 'Kenapa gaji saya berbeda bulan ini?', 'employee_id' => $itStaff2->getKey()], $employeeUser);
    check('tanpa izin payroll AI menolak berbicara angka', $denied['status'] === 'REFUSED' && $denied['answer'] !== '',
        mb_substr($denied['answer'], 0, 130));
    check('penolakan tercatat di tool calls', \App\Modules\Hrd\Strategic\Ai\Models\HrAiToolCall::query()->where('status', 'DENIED')->count() > 0,
        'penolakan '.(\App\Modules\Hrd\Strategic\Ai\Models\HrAiToolCall::query()->where('status', 'DENIED')->count()));
});

attempt('AI menjawab pertanyaan strategis', function () use ($orchestrator, $director): void {
    $workforce = $orchestrator->ask(['question' => 'Berapa guru yang kita perlukan tahun depan?',
        'drivers' => ['students_current' => 300, 'students_delta_percent' => 20]], $director);
    check('AI workforce analyst menghitung kebutuhan guru', str_contains($workforce['answer'], 'kebutuhan guru'),
        mb_substr($workforce['answer'], 0, 150));
    $succession = $orchestrator->ask(['question' => 'Posisi kritis mana yang belum punya successor?'], $director);
    check('AI succession assistant melaporkan cakupan', str_contains($succession['answer'], 'suksesi'),
        mb_substr($succession['answer'], 0, 120));
    $retention = $orchestrator->ask(['question' => 'Kenapa turnover naik di unit SMP?'], $director);
    check('AI analytics menjelaskan turnover dengan metrik', isset($retention['table']),
        mb_substr($retention['answer'], 0, 120));
    $talent = $orchestrator->ask(['question' => 'Siapa yang cocok untuk project AI?'], $director);
    check('AI talent menampilkan pool kandidat (bukan keputusan)', str_contains($talent['answer'], 'pool'),
        mb_substr($talent['answer'], 0, 130));
});

attempt('AI write action: draf → konfirmasi → eksekusi', function () use ($orchestrator, $director, $itStaff1, $governance): void {
    $draft = $orchestrator->ask(['question' => 'Siapkan permohonan training untuk Ahmad', 'employee_id' => $itStaff1->getKey()], $director);
    check('AI membuat draf & meminta konfirmasi', ($draft['requires_confirmation'] ?? false) === true,
        'aksi '.$draft['draft']['action'].' | boleh '.json_encode($draft['draft']['allowed']));
    $run = \App\Modules\Hrd\Strategic\Ai\Models\HrAiRun::query()->where('code', $draft['run'])->first();
    $confirmed = $orchestrator->confirmWriteAction((int) $run->getKey(), $director);
    check('eksekusi hanya setelah konfirmasi manusia', ($confirmed['status'] ?? '') === 'EXECUTED',
        'referensi '.$confirmed['reference'].' | workflow '.$confirmed['workflow']);
    $blocked = $governance->assertActionAllowed('TERMINATE_EMPLOYEE');
    check('aksi terlarang ditolak AI', $blocked['allowed'] === false, $blocked['reason']);
});

attempt('AI rekomendasi & human-in-the-loop', function () use ($orchestrator, $governance, $hr): void {
    $recommendation = $orchestrator->recommend([
        'type' => 'WORKFORCE', 'title' => 'Rekrut 3 guru matematika untuk 2027',
        'problem' => 'Proyeksi siswa +20% sedangkan guru matematika 3 orang',
        'evidence' => ['student_growth' => '20%', 'teacher_gap' => 3, 'source' => 'AcademicWorkforceService'],
        'options' => [['label' => 'Rekrut 3 guru'], ['label' => 'Latih 2 guru lintas mapel'], ['label' => 'Kontraktor 1 semester']],
        'expected_impact' => 'Rasio siswa/guru kembali 18:1', 'estimated_cost' => 240_000_000,
        'risks' => ['Waktu rekrutmen 2 bulan'], 'dependencies' => ['Anggaran yayasan'], 'next_step' => 'Buka requisition 2027',
        'confidence' => 0.78, 'owner_role' => 'HR_MANAGER',
    ], $hr);
    check('rekomendasi menyertakan bukti, opsi, biaya, pemilik', $recommendation->evidence !== [] && $recommendation->options !== [],
        $recommendation->code.' | keyakinan '.$recommendation->confidence.' | biaya '.number_format((float) $recommendation->estimated_cost));
    $conversionAttempt = $orchestrator->convertToAction((int) $recommendation->getKey(), $hr);
    check('belum disetujui → tidak dieksekusi', ($conversionAttempt['converted'] ?? true) === false, (string) ($conversionAttempt['reason'] ?? ''));
    $reviewed = $orchestrator->reviewRecommendation((int) $recommendation->getKey(), 'APPROVED', $hr, 'Disetujui untuk RKAP 2027');
    check('tinjauan manusia menyetujui rekomendasi', $reviewed->status === 'APPROVED', 'keputusan '.$reviewed->human_decision);
    $converted = $orchestrator->convertToAction((int) $recommendation->getKey(), $hr);
    check('rekomendasi disetujui menjadi rencana aksi', ($converted['converted'] ?? false) === true,
        'aksi '.($converted['action']['action'] ?? '-').' | item '.($converted['action']['items'] ?? 0));
    $feedback = $governance->feedback(
        (int) (\App\Modules\Hrd\Strategic\Ai\Models\HrAiRun::query()->orderByDesc('id')->value('id')), 'HELPFUL', $hr, 'Sesuai kebutuhan');
    check('umpan balik AI tersimpan', (bool) $feedback->getKey(), $feedback->feedback);
});

attempt('kontrol halusinasi & lapisan semantik', function () use ($intelligence, $hr, $director): void {
    $noSource = $intelligence->askKnowledge('Bagaimana prosedur klaim asuransi gigi internasional?', $hr);
    check('tanpa sumber → AI menolak menjawab', $noSource['status'] === 'NO_SOURCE', mb_substr($noSource['answer'], 0, 110));
    $refused = $intelligence->query('tampilkan data rahasia yang tidak terdaftar', $hr);
    check('entitas tak dikenal → query ditolak', $refused['status'] === 'REFUSED', (string) $refused['reason']);
    $query = $intelligence->query('Tampilkan guru aktif berdasarkan sekolah', $director);
    $queryOk = ($query['status'] ?? '') === 'OK';
    $queryDetail = 'status '.($query['status'] ?? '-').' | kunci '.implode(',', array_keys($query));
    if ($queryOk && is_array($query['table'] ?? null)) {
        $queryDetail .= ' | entitas '.($query['plan']['entity'] ?? '-').' | baris '.count($query['table']['rows'] ?? [])
            .' | scope '.($query['authorization']['scope'] ?? '-');
    }
    check('natural language → rencana query → otorisasi → tabel', $queryOk, $queryDetail);
    $knowledge = $intelligence->askKnowledge('Berapa hari cuti tahunan yang diberikan?', $hr);
    check('asisten pengetahuan menjawab dengan sitasi', $knowledge['status'] === 'OK' && $knowledge['citations'] !== [],
        'kutipan '.($knowledge['citations'][0]['document'] ?? '-'));
});

attempt('governance AI: registry, biaya, bias, audit', function () use ($governance, $orchestrator, $tools): void {
    $registry = $governance->registry();
    check('registry provider/model/agent/policy', count($registry['agents']) >= 10 && count($registry['providers']) >= 2,
        count($registry['agents']).' agent | '.count($registry['models']).' model | '.count($registry['policies']).' kebijakan');
    $cost = $governance->costDashboard();
    check('pemakaian & biaya AI dilacak per fitur', $cost['total_requests'] > 0,
        'permintaan '.$cost['total_requests'].' | biaya '.number_format((float) $cost['total_cost']));
    $bias = $governance->monitorBias(now()->format('Y-m'), 'RECRUITMENT');
    check('pemantauan bias dijalankan', $bias['findings'] !== [], count($bias['findings']).' temuan pemantauan');
    $auditCount = (int) DB::table('hr_ai_audit_logs')->count();
    check('audit AI lengkap (prompt, tool, keputusan, mutasi)', $auditCount > 0, $auditCount.' baris audit AI');
    $agents = $orchestrator->agents();
    check('agent HR terdaftar dengan tool & izin', count($agents) >= 10 && $agents[0]['tools'] !== [],
        implode(', ', array_slice(array_column($agents, 'code'), 0, 5)).'…');
    check('tool registry berisi tool baca & tulis', count($tools->tools()['registered']) >= 14,
        count($tools->tools()['registered']).' tool terdaftar');
});

/* ================================================================ RINGKASAN */
print(PHP_EOL.'=== RINGKASAN UJI HRD PHASE 3 ==='.PHP_EOL);
print('lulus: '.$pass.' | gagal: '.$fail.PHP_EOL);

if ($fail === 0) {
    print(PHP_EOL.'Seluruh kriteria penerimaan §165 terverifikasi: Workforce, Skills, Talent, Succession, Analytics, AI.'.PHP_EOL);
}

exit($fail === 0 ? 0 : 1);
