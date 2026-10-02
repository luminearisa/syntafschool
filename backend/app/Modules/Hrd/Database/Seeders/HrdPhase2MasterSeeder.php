<?php

namespace App\Modules\Hrd\Database\Seeders;

use App\Models\User;
use App\Modules\Hrd\Benefits\Services\BenefitService;
use App\Modules\Hrd\Career\Services\CareerService;
use App\Modules\Hrd\Core\Models\HrRule;
use App\Modules\Hrd\Core\Models\HrRuleCondition;
use App\Modules\Hrd\Core\Services\AlertService;
use App\Modules\Hrd\Learning\Services\LearningService;
use App\Modules\Hrd\Leave\Services\HolidayCalendarService;
use App\Modules\Hrd\Leave\Services\LeavePolicyService;
use App\Modules\Hrd\Models\HrLeaveType;
use App\Modules\Hrd\Payroll\Models\HrTaxRule;
use App\Modules\Hrd\Payroll\Services\SalaryStructureService;
use App\Modules\Hrd\Performance\Models\HrRatingScale;
use App\Modules\Hrd\Performance\Models\HrRatingScaleLevel;
use App\Modules\Hrd\Performance\Services\PerformanceService;
use App\Modules\Hrd\Recruitment\Models\HrAssessmentDefinition;
use App\Modules\Hrd\Recruitment\Models\HrCandidateSource;
use App\Modules\Hrd\Recruitment\Models\HrPipelineStage;
use App\Modules\Hrd\Recruitment\Models\HrScorecardTemplate;
use App\Modules\Hrd\Talent\Services\TalentService;
use App\Modules\Hrd\Workforce\Services\ScheduleService;
use Illuminate\Database\Seeder;

/**
 * Data master Phase 2: corong rekrutmen, jenis cuti + kebijakan, kalender libur,
 * komponen & struktur gaji, aturan pajak, skala rating, katalog kompetensi,
 * katalog pelatihan + pelatihan wajib, jalur karier, kumpulan talenta, dan
 * contoh aturan kelayakan.
 *
 * Semuanya idempoten (`updateOrCreate`) sehingga dapat dijalankan berulang.
 */
class HrdPhase2MasterSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->whereHas('roles', fn ($query) => $query->whereIn('name', ['HR_ADMIN', 'super-admin']))->first()
            ?? User::query()->first();

        if ($actor === null) {
            // Basis data baru mungkin belum memiliki pengguna sama sekali;
            // seeder membuat satu akun sistem agar data master tetap terbentuk.
            $actor = User::query()->firstOrCreate(
                ['email' => 'hrd.seeder@syntaf.local'],
                [
                    'name' => 'HRD Seeder',
                    'password' => bcrypt(str()->random(32)),
                    'is_active' => true,
                ],
            );

            $actor->syncRoles(['HR_ADMIN']);
            $this->command?->warn('Akun sistem dibuat untuk seeding: '.$actor->email);
        }

        $this->seedPipeline();
        $this->seedSourcesAndAssessments();
        $this->seedSchedules();
        $this->seedLeave($actor);
        $this->seedPayroll($actor);
        $this->seedPerformance($actor);
        $this->seedLearning($actor);
        $this->seedCareerAndTalent($actor);
        $this->seedBenefits($actor);
        $this->seedRules();

        $this->command?->info('Master HRD Phase 2 selesai di-seed.');
    }

    private function seedPipeline(): void
    {
        foreach (config('hrd_phase2.recruitment.pipeline') as $index => $stage) {
            HrPipelineStage::query()->updateOrCreate(
                ['code' => $stage],
                [
                    'label' => ucwords(strtolower(str_replace('_', ' ', $stage))),
                    'sequence' => $index + 1,
                    'is_terminal' => in_array($stage, config('hrd_phase2.recruitment.terminal_stages'), true),
                    'is_default' => $index === 0,
                    'sla_days' => 5,
                ],
            );
        }

        $template = HrScorecardTemplate::query()->updateOrCreate(
            ['code' => 'SCORECARD-DEFAULT'],
            ['name' => 'Scorecard Wawancara Standar', 'description' => 'Penilaian pewawancara per aspek.'],
        );

        foreach ([['Komunikasi', 20], ['Keahlian Teknis', 30], ['Pemecahan Masalah', 20], ['Kepemimpinan', 15], ['Kesesuaian Nilai', 15]] as $index => [$label, $weight]) {
            \App\Modules\Hrd\Recruitment\Models\HrScorecardTemplateItem::query()->updateOrCreate(
                ['template_id' => $template->getKey(), 'competency_label' => $label],
                ['weight' => $weight, 'max_score' => 5, 'sequence' => $index + 1],
            );
        }
    }

    private function seedSourcesAndAssessments(): void
    {
        foreach (config('hrd_phase2.recruitment.sources') as $source) {
            HrCandidateSource::query()->updateOrCreate(
                ['code' => $source],
                ['name' => ucwords(strtolower(str_replace('_', ' ', $source))), 'category' => 'DIGITAL'],
            );
        }

        foreach ([
            ['TECHNICAL-TEST', 'Tes Teknis', 'TECHNICAL_TEST', 100, 70],
            ['TEACHING-DEMO', 'Demo Mengajar', 'TEACHING_DEMO', 100, 75],
            ['PSYCHOMETRIC', 'Tes Psikometri', 'PSYCHOMETRIC_TEST', 100, 60],
            ['CASE-STUDY', 'Studi Kasus', 'CASE_STUDY', 100, 65],
        ] as [$code, $name, $category, $max, $passing]) {
            HrAssessmentDefinition::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'category' => $category, 'max_score' => $max, 'passing_score' => $passing, 'weight' => 1],
            );
        }
    }

    private function seedSchedules(): void
    {
        $schedules = app(ScheduleService::class);

        $morning = $schedules->createShift(['code' => 'PAGI', 'name' => 'Shift Pagi', 'start_time' => '07:00', 'end_time' => '15:00', 'break_start' => '12:00', 'break_end' => '13:00', 'shift_type' => 'MORNING'], User::query()->firstOrFail());
        $afternoon = $schedules->createShift(['code' => 'SIANG', 'name' => 'Shift Siang', 'start_time' => '13:00', 'end_time' => '21:00', 'shift_type' => 'AFTERNOON'], User::query()->firstOrFail());
        $night = $schedules->createShift(['code' => 'MALAM', 'name' => 'Shift Malam', 'start_time' => '22:00', 'end_time' => '06:00', 'cross_midnight' => true, 'shift_type' => 'NIGHT'], User::query()->firstOrFail());

        \App\Modules\Hrd\Workforce\Models\HrShift::query()->updateOrCreate(['code' => 'PAGI'], ['total_hours' => $morning->total_hours]);
        \App\Modules\Hrd\Workforce\Models\HrShift::query()->updateOrCreate(['code' => 'SIANG'], ['total_hours' => $afternoon->total_hours]);
        \App\Modules\Hrd\Workforce\Models\HrShift::query()->updateOrCreate(['code' => 'MALAM'], ['total_hours' => $night->total_hours]);

        $schedules->createSchedule([
            'code' => 'REGULER',
            'name' => 'Jadwal Reguler Senin–Jumat',
            'schedule_type' => 'FIXED',
            'working_days' => [1, 2, 3, 4, 5],
            'is_default' => true,
            'days' => array_map(fn (int $weekday) => [
                'weekday' => $weekday,
                'is_working' => true,
                'shift_id' => $morning->getKey(),
                'start_time' => '07:00',
                'end_time' => '15:00',
                'break_minutes' => 60,
                'hours' => 7,
            ], [1, 2, 3, 4, 5]),
        ], User::query()->firstOrFail());

        $schedules->createRotation([
            'code' => 'ROTASI-3MINGGU',
            'name' => 'Rotasi 3 Minggu (Pagi → Siang → Malam)',
            'pattern' => 'WEEKLY',
            'cycle_weeks' => 3,
            'patterns' => [
                ['week_index' => 1, 'weekday' => 1, 'shift_id' => $morning->getKey(), 'label' => 'Pagi'],
                ['week_index' => 2, 'weekday' => 1, 'shift_id' => $afternoon->getKey(), 'label' => 'Siang'],
                ['week_index' => 3, 'weekday' => 1, 'shift_id' => $night->getKey(), 'label' => 'Malam'],
            ],
        ], User::query()->firstOrFail());

        foreach ([
            ['KAMPUS-UTAMA', 'Kampus Utama', 'CAMPUS', -6.2, 106.8, 250],
        ] as [$code, $name, $type, $lat, $lng, $radius]) {
            \App\Modules\Hrd\Workforce\Models\HrAttendanceLocation::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'location_type' => $type, 'latitude' => $lat, 'longitude' => $lng, 'radius_meters' => $radius, 'geofence_enabled' => true],
            );
        }

        foreach ([
            ['DEV-GATE-01', 'Gerbang Utama', 'device_api'],
            ['DEV-FACE-01', 'Terminal Wajah Lobi', 'face'],
        ] as [$code, $name, $provider]) {
            \App\Modules\Hrd\Workforce\Models\HrAttendanceDevice::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'provider' => $provider, 'is_active' => true],
            );
        }
    }

    private function seedLeave(User $actor): void
    {
        $types = [
            ['ANNUAL', 'Cuti Tahunan', true, false, 12],
            ['SICK', 'Cuti Sakit', true, true, 12],
            ['MATERNITY', 'Cuti Melahirkan', true, true, 90],
            ['PATERNITY', 'Cuti Ayah', true, false, 2],
            ['MARRIAGE', 'Cuti Menikah', true, false, 3],
            ['BEREAVEMENT', 'Cuti Kedukaan', true, false, 3],
            ['RELIGIOUS', 'Cuti Keagamaan', true, false, 3],
            ['STUDY', 'Cuti Belajar', true, true, 30],
            ['UNPAID', 'Cuti Tanpa Upah', false, true, 0],
            ['SPECIAL', 'Cuti Khusus', true, false, 5],
        ];

        foreach ($types as [$code, $name, $paid, $document, $days]) {
            HrLeaveType::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'is_paid' => $paid, 'requires_document' => $document, 'max_days_per_year' => $days, 'is_active' => true],
            );
        }

        $policies = app(LeavePolicyService::class);
        $annual = HrLeaveType::query()->where('code', 'ANNUAL')->first();

        if ($annual !== null) {
            $policies->createPolicy([
                'code' => 'POL-ANNUAL-STAFF',
                'name' => 'Cuti Tahunan Pegawai Tetap',
                'leave_type_id' => $annual->getKey(),
                'employment_type' => 'Permanent',
                'entitlement_days' => 12,
                'accrual_method' => 'MONTHLY',
                'accrual_rate' => 1,
                'carry_forward' => true,
                'carry_forward_limit' => 6,
                'min_notice_days' => 3,
                'priority' => 10,
            ], $actor);
        }

        $sick = HrLeaveType::query()->where('code', 'SICK')->first();

        if ($sick !== null) {
            $policies->createPolicy([
                'code' => 'POL-SICK-ALL',
                'name' => 'Cuti Sakit (semua pegawai)',
                'leave_type_id' => $sick->getKey(),
                'entitlement_days' => 12,
                'accrual_method' => 'ANNUAL',
                'requires_document' => true,
                'document_after_days' => 1,
                'min_notice_days' => 0,
                'priority' => 5,
            ], $actor);
        }

        $unpaid = HrLeaveType::query()->where('code', 'UNPAID')->first();

        if ($unpaid !== null) {
            $policies->createPolicy([
                'code' => 'POL-UNPAID-ALL',
                'name' => 'Cuti Tanpa Upah',
                'leave_type_id' => $unpaid->getKey(),
                'entitlement_days' => 0,
                'accrual_method' => 'NONE',
                'paid' => false,
                'min_notice_days' => 7,
                'priority' => 1,
            ], $actor);
        }

        $calendar = app(HolidayCalendarService::class)->create([
            'code' => 'HOLIDAY-NATIONAL-'.now()->year,
            'name' => 'Hari Libur Nasional '.now()->year,
            'calendar_type' => 'NATIONAL',
            'year' => now()->year,
            'is_default' => true,
        ], $actor);

        app(HolidayCalendarService::class)->addHoliday($calendar, ['date' => now()->year.'-08-17', 'name' => 'Hari Kemerdekaan', 'holiday_type' => 'NATIONAL']);
        app(HolidayCalendarService::class)->addHoliday($calendar, ['date' => now()->year.'-12-25', 'name' => 'Hari Raya Natal', 'holiday_type' => 'RELIGIOUS']);
        app(HolidayCalendarService::class)->addHoliday($calendar, ['date' => now()->year.'-01-01', 'name' => 'Tahun Baru', 'holiday_type' => 'NATIONAL']);
    }

    private function seedPayroll(User $actor): void
    {
        $structures = app(SalaryStructureService::class);

        $components = [
            ['BASIC', 'Gaji Pokok', 'EARNING', true, true],
            ['TRANSPORT', 'Tunjangan Transport', 'EARNING', true, false],
            ['MEAL', 'Tunjangan Makan', 'EARNING', true, false],
            ['POSITION', 'Tunjangan Jabatan', 'EARNING', true, false],
            ['ATTENDANCE', 'Tunjangan Kehadiran', 'EARNING', true, true],
            ['THR', 'Tunjangan Hari Raya', 'EARNING', true, false],
            ['BENEFIT_BPJS_KES', 'Iuran BPJS Kesehatan (Pegawai)', 'DEDUCTION', false, false],
            ['LOAN', 'Angsuran Pinjaman', 'DEDUCTION', false, false],
            ['PPH21', 'PPh 21', 'TAX', false, false],
        ];

        foreach ($components as [$code, $name, $kind, $taxable, $prorated]) {
            $structures->createComponent([
                'code' => $code, 'name' => $name, 'kind' => $kind,
                'is_taxable' => $taxable, 'is_prorated' => $prorated,
                'gl_account_code' => $kind === 'EARNING' ? '5101' : '2103',
            ], $actor);
        }

        $structures->createStructure([
            'code' => 'STRUCT-TEACHER',
            'name' => 'Struktur Gaji Guru',
            'employment_type' => 'Permanent',
            'base_amount' => 5000000,
            'is_default' => true,
        ], [
            ['component_code' => 'BASIC', 'amount' => 5000000, 'order_index' => 1],
            ['component_code' => 'TRANSPORT', 'amount' => 600000, 'order_index' => 2],
            ['component_code' => 'MEAL', 'amount' => 450000, 'order_index' => 3],
            ['component_code' => 'ATTENDANCE', 'amount' => 300000, 'order_index' => 4],
        ], $actor);

        HrTaxRule::query()->updateOrCreate(
            ['code' => 'PPH21-'.(string) config('hrd_phase2.payroll.tax_version'), 'tax_type' => 'PPH21'],
            [
                'version' => (string) config('hrd_phase2.payroll.tax_version'),
                'effective_from' => now()->startOfYear()->toDateString(),
                'brackets' => [
                    ['limit' => 60000000, 'rate' => 0.05],
                    ['limit' => 250000000, 'rate' => 0.15],
                    ['limit' => 500000000, 'rate' => 0.25],
                    ['limit' => 5000000000, 'rate' => 0.30],
                ],
                'rates' => ['ptkp_base' => 54000000, 'ptkp_married' => 4500000, 'ptkp_dependent' => 4500000],
                'is_active' => true,
            ],
        );

        foreach ([['BPJS_KES_EMPLOYEE', 1.0, 4.0], ['BPJS_TK_EMPLOYEE', 2.0, 3.7]] as [$type, $employeeRate, $employerRate]) {
            HrTaxRule::query()->updateOrCreate(
                ['code' => $type.'-'.(string) config('hrd_phase2.payroll.tax_version'), 'tax_type' => $type],
                [
                    'version' => (string) config('hrd_phase2.payroll.tax_version'),
                    'effective_from' => now()->startOfYear()->toDateString(),
                    'employee_rate' => $employeeRate,
                    'employer_rate' => $employerRate,
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedPerformance(User $actor): void
    {
        $scale = HrRatingScale::query()->updateOrCreate(
            ['code' => 'SCALE_1_5'],
            ['name' => 'Skala 1–5', 'scale_type' => 'NUMERIC', 'min_value' => 1, 'max_value' => 5, 'is_default' => true],
        );

        foreach ([
            ['Outstanding', 4.5, 5, 'Melampaui harapan secara konsisten'],
            ['Exceeds Expectations', 3.8, 4.49, 'Sering melampaui harapan'],
            ['Meets Expectations', 2.5, 3.79, 'Memenuhi harapan'],
            ['Needs Improvement', 1.5, 2.49, 'Perlu perbaikan terarah'],
            ['Unsatisfactory', 1, 1.49, 'Belum memenuhi harapan'],
        ] as $index => [$label, $min, $max, $description]) {
            HrRatingScaleLevel::query()->updateOrCreate(
                ['scale_id' => $scale->getKey(), 'label' => $label],
                ['min_value' => $min, 'max_value' => $max, 'sequence' => $index + 1, 'description' => $description],
            );
        }

        $performance = app(PerformanceService::class);

        foreach ([
            ['CORE-PEDAGOGY', 'Pedagogi', 'CORE', 5],
            ['CORE-INTEGRITY', 'Integritas', 'BEHAVIORAL', 5],
            ['LEAD-COACHING', 'Pembinaan Tim', 'LEADERSHIP', 5],
            ['TECH-DIGITAL', 'Literasi Digital', 'TECHNICAL', 5],
        ] as [$code, $name, $category, $maxLevel]) {
            $performance->createCompetency([
                'code' => $code, 'name' => $name, 'category' => $category, 'max_level' => $maxLevel,
                'description' => 'Kompetensi '.$name.' untuk pegawai sekolah.',
            ], $actor, [
                ['level' => 1, 'label' => 'Dasar', 'indicators' => ['Mengenal konsep dasar']],
                ['level' => 3, 'label' => 'Mahir', 'indicators' => ['Menerapkan secara mandiri']],
                ['level' => 5, 'label' => 'Ahli', 'indicators' => ['Menjadi rujukan dan membina orang lain']],
            ]);
        }

        foreach ([['HADIR-GURU', 'Kehadiran Guru', 'PERCENTAGE', 'ATTENDANCE'], ['NILAI-SISWA', 'Pencapaian Akademik Siswa', 'NUMBER', 'ACADEMIC']] as [$code, $name, $metric, $source]) {
            \App\Modules\Hrd\Performance\Models\HrKpiDefinition::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'metric_type' => $metric, 'frequency' => 'MONTHLY', 'source_module' => $source, 'target_default' => 95],
            );
        }
    }

    private function seedLearning(User $actor): void
    {
        $learning = app(LearningService::class);

        $trainings = [
            ['CHILD-PROTECTION', 'Perlindungan Anak', 'MANDATORY', 4, 0, true, ['CORE-INTEGRITY']],
            ['SAFETY-TRAINING', 'Keselamatan Kerja', 'MANDATORY', 6, 0, true, []],
            ['DIGITAL-LITERACY', 'Literasi Digital Guru', 'WORKSHOP', 8, 750000, false, ['TECH-DIGITAL']],
            ['CLASSROOM-MANAGEMENT', 'Manajemen Kelas', 'COURSE', 12, 1200000, false, ['CORE-PEDAGOGY']],
        ];

        foreach ($trainings as [$code, $title, $type, $hours, $cost, $mandatory, $competencies]) {
            $learning->createTraining([
                'code' => $code, 'title' => $title, 'training_type' => $type,
                'duration_hours' => $hours, 'cost' => $cost, 'is_mandatory' => $mandatory,
                'competencies' => $competencies, 'provider' => $cost > 0 ? 'EXTERNAL' : 'INTERNAL',
                'certification_code' => $mandatory ? $code : null, 'validity_months' => $mandatory ? 24 : null,
            ], $actor);
        }

        foreach ([
            ['REQ-CHILD-PROTECTION', 'CHILD-PROTECTION', 'EMPLOYMENT_TYPE', ['employment_type' => 'Permanent'], 30],
            ['REQ-SAFETY', 'SAFETY-TRAINING', 'ORGANIZATION', [], 60],
        ] as [$code, $trainingCode, $scope, $extra, $dueDays]) {
            $training = \App\Modules\Hrd\Learning\Models\HrTrainingCatalog::query()->where('code', $trainingCode)->first();

            if ($training !== null) {
                $learning->addRequirement(array_merge([
                    'code' => $code, 'training_id' => $training->getKey(),
                    'scope' => $scope, 'is_mandatory' => true, 'due_days' => $dueDays,
                ], $extra), $actor);
            }
        }
    }

    private function seedCareerAndTalent(User $actor): void
    {
        app(CareerService::class)->createPath([
            'code' => 'PATH-TEACHER',
            'name' => 'Jalur Guru → Kepala Sekolah',
            'description' => 'Guru → Guru Senior → Koordinator → Kepala Departemen → Wakil Kepala → Kepala Sekolah',
        ], $actor, [
            ['sequence' => 1, 'grade_code' => 'G3', 'min_years_experience' => 0, 'required_skills' => [['code' => 'PEDAGOGY', 'level' => 'INTERMEDIATE']]],
            ['sequence' => 2, 'grade_code' => 'G4', 'min_years_experience' => 3, 'required_skills' => [['code' => 'PEDAGOGY', 'level' => 'ADVANCED']]],
            ['sequence' => 3, 'grade_code' => 'G5', 'min_years_experience' => 5, 'required_skills' => [['code' => 'LEADERSHIP', 'level' => 'ADVANCED']]],
            ['sequence' => 4, 'grade_code' => 'G6', 'min_years_experience' => 8, 'required_skills' => [['code' => 'LEADERSHIP', 'level' => 'EXPERT']]],
        ]);

        $talent = app(TalentService::class);

        foreach (config('hrd_phase2.talent.pools') as $pool) {
            $talent->createPool(['code' => $pool, 'name' => ucwords(strtolower(str_replace('_', ' ', $pool))), 'pool_type' => $pool], $actor);
        }

        foreach ([['AWARD-EMPLOYEE', 'Pegawai Teladan', 'AWARD', 100], ['BADGE-HELPER', 'Relawan Sekolah', 'BADGE', 25], ['MILESTONE-5Y', 'Lima Tahun Pengabdian', 'MILESTONE', 50]] as [$code, $name, $category, $points]) {
            $talent->createAward(['code' => $code, 'name' => $name, 'category' => $category, 'points' => $points], $actor);
        }
    }

    private function seedBenefits(User $actor): void
    {
        $benefits = app(BenefitService::class);

        foreach ([
            ['BPJS_KES', 'BPJS Kesehatan', 'HEALTH', true],
            ['BPJS_TK', 'BPJS Ketenagakerjaan', 'PROTECTION', true],
            ['HEALTH-EXTRA', 'Asuransi Kesehatan Tambahan', 'INSURANCE', false],
            ['MEAL-ALLOW', 'Tunjangan Makan', 'ALLOWANCE', false],
        ] as [$code, $name, $category, $statutory]) {
            \App\Modules\Hrd\Benefits\Models\HrBenefitType::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'category' => $category, 'is_statutory' => $statutory],
            );
        }

        $benefits->createPlan([
            'code' => 'PLAN-BPJS-KES',
            'name' => 'BPJS Kesehatan (Pegawai)',
            'benefit_type_code' => 'BPJS_KES',
            'employee_contribution' => 50000,
            'employer_contribution' => 200000,
            'salary_component_code' => 'BENEFIT_BPJS_KES',
            'enrollment_window' => 'NEW_HIRE',
        ], $actor);

        $benefits->createPlan([
            'code' => 'PLAN-HEALTH-EXTRA',
            'name' => 'Asuransi Kesehatan Tambahan',
            'benefit_type_code' => 'HEALTH-EXTRA',
            'employee_contribution' => 75000,
            'employer_contribution' => 150000,
            'requires_dependents' => true,
            'max_dependents' => 4,
            'enrollment_window' => 'ANNUAL',
        ], $actor);
    }

    private function seedRules(): void
    {
        $rules = [
            [
                'code' => 'RULE-PERMANENT-ANNIVERSARY',
                'name' => 'Pegawai tetap dengan masa kerja ≥ 1 tahun',
                'purpose' => 'BENEFIT_ELIGIBILITY',
                'action_type' => 'ELIGIBLE',
                'action_payload' => ['note' => 'Layak benefit yang mensyaratkan masa kerja.'],
                'conditions' => [
                    ['field' => 'employment_type', 'operator' => '=', 'value' => 'Permanent'],
                    ['field' => 'tenure_months', 'operator' => '>=', 'value' => 12],
                ],
            ],
            [
                'code' => 'RULE-CONTRACT-NO-ANNUAL-LEAVE',
                'name' => 'Pegawai kontrak belum berhak cuti tahunan penuh',
                'purpose' => 'LEAVE_ELIGIBILITY',
                'action_type' => 'NOT_ELIGIBLE',
                'action_payload' => ['reason' => 'Cuti tahunan penuh hanya untuk pegawai tetap.'],
                'priority' => 5,
                'conditions' => [
                    ['field' => 'employment_type', 'operator' => '=', 'value' => 'Contract'],
                ],
            ],
            [
                'code' => 'RULE-TEACHER-CHILD-PROTECTION',
                'name' => 'Guru wajib pelatihan perlindungan anak',
                'purpose' => 'TRAINING_REQUIREMENT',
                'action_type' => 'REQUIRE',
                'action_payload' => ['training_code' => 'CHILD-PROTECTION', 'due_days' => 30],
                'conditions' => [
                    ['field' => 'worker_type', 'operator' => '=', 'value' => 'Teacher'],
                ],
            ],
            [
                'code' => 'RULE-CAREER-READY-GRADE',
                'name' => 'Pegawai golongan ≥ G4 layak mengikuti jalur karier lanjutan',
                'purpose' => 'CAREER_ELIGIBILITY',
                'action_type' => 'ELIGIBLE',
                'action_payload' => ['note' => 'Boleh melamar posisi internal satu tingkat di atasnya.'],
                'conditions' => [
                    ['field' => 'grade_code', 'operator' => 'IN', 'value' => ['G4', 'G5', 'G6']],
                ],
            ],
        ];

        foreach ($rules as $definition) {
            $rule = HrRule::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'purpose' => $definition['purpose'],
                    'match_mode' => 'ALL',
                    'priority' => $definition['priority'] ?? 10,
                    'action_type' => $definition['action_type'],
                    'action_payload' => $definition['action_payload'],
                    'effective_from' => now()->startOfYear()->toDateString(),
                    'is_active' => true,
                ],
            );

            foreach ($definition['conditions'] as $index => $condition) {
                HrRuleCondition::query()->updateOrCreate(
                    ['rule_id' => $rule->getKey(), 'field' => $condition['field']],
                    ['operator' => $condition['operator'], 'value' => is_array($condition['value']) ? $condition['value'] : [$condition['value']], 'sequence' => $index + 1],
                );
            }
        }
    }
}
