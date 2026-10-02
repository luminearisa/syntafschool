<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Database\Seeders;

use App\Modules\Hrd\Strategic\Ai\Models\HrAiAgent;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiModel;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiPolicy;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiProvider;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiTool;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiToolPermission;
use App\Modules\Hrd\Strategic\Ai\Services\AiIntelligenceService;
use App\Modules\Hrd\Strategic\Analytics\Models\HrAnalyticsDashboard;
use App\Modules\Hrd\Strategic\Analytics\Models\HrAnalyticsWidget;
use App\Modules\Hrd\Strategic\Analytics\Models\HrDataCatalog;
use App\Modules\Hrd\Strategic\Analytics\Models\HrDataQualityRule;
use App\Modules\Hrd\Strategic\Analytics\Models\HrSemanticEntity;
use App\Modules\Hrd\Strategic\Analytics\Models\HrSemanticField;
use App\Modules\Hrd\Strategic\Analytics\Services\MetricEngine;
use App\Modules\Hrd\Strategic\Experience\Services\JourneyAutomationService;
use App\Modules\Hrd\Strategic\Forecasting\Services\ForecastService;
use App\Modules\Hrd\Strategic\People\Models\HrJourneyDefinition;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillCategory;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillDomain;
use App\Modules\Hrd\Strategic\Succession\Models\HrCriticalPosition;
use App\Modules\Hrd\Strategic\Talent\Models\HrOpportunityType;
use App\Modules\Hrd\Strategic\Talent\Services\TalentMarketplaceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Data fondasi Phase 3: izin & peran, definisi metrik kanonik, lapisan
 * semantik, katalog data, taksonomi keterampilan, jenis peluang, model
 * prakiraan, provider/agent/tool AI beserta kebijakannya, dashboard, dan
 * perjalanan pegawai.
 */
class HrdPhase3MasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAccess();
        $this->seedSkillTaxonomy();
        $this->seedMetrics();
        $this->seedSemanticLayer();
        $this->seedCatalog();
        $this->seedOpportunityTypes();
        $this->seedForecastModels();
        $this->seedAi();
        $this->seedDashboards();
        $this->seedJourneys();
        $this->seedAutomationRules();
        $this->seedKnowledge();
    }

    private function seedAccess(): void
    {
        $permissions = [
            'hr.strategic.view', 'hr.strategic.manage', 'hr.workforce.plan', 'hr.analytics.view', 'hr.analytics.manage',
            'hr.skills.view', 'hr.skills.manage', 'hr.talent.view', 'hr.talent.manage', 'hr.succession.view',
            'hr.succession.manage', 'hr.compensation.view', 'hr.compensation.manage', 'hr.retention.view',
            'hr.ai.use', 'hr.ai.manage', 'hr.risk.view', 'hr.risk.manage', 'hr.metric.view', 'hr.marketplace.view',
            'hr.marketplace.manage', 'hr.forecast.view', 'hr.forecast.manage', 'hr.experience.view', 'hr.engagement.view',
        ];
        $roles = [
            'HR_STRATEGIST' => ['hr.strategic.view', 'hr.strategic.manage', 'hr.workforce.plan', 'hr.analytics.view',
                'hr.analytics.manage', 'hr.forecast.view', 'hr.forecast.manage', 'hr.metric.view', 'hr.risk.view', 'hr.risk.manage'],
            'WORKFORCE_PLANNER' => ['hr.strategic.view', 'hr.workforce.plan', 'hr.skills.view', 'hr.analytics.view', 'hr.forecast.view'],
            'PEOPLE_ANALYST' => ['hr.analytics.view', 'hr.analytics.manage', 'hr.metric.view', 'hr.retention.view',
                'hr.compensation.view', 'hr.experience.view', 'hr.engagement.view', 'hr.ai.use'],
            'TALENT_MANAGER' => ['hr.talent.view', 'hr.talent.manage', 'hr.marketplace.view', 'hr.marketplace.manage',
                'hr.succession.view', 'hr.skills.view', 'hr.ai.use'],
            'SUCCESSION_PLANNER' => ['hr.succession.view', 'hr.succession.manage', 'hr.talent.view', 'hr.skills.view'],
            'COMPENSATION_ANALYST' => ['hr.compensation.view', 'hr.compensation.manage', 'hr.analytics.view', 'hr.metric.view'],
            'HR_AI_STEWARD' => ['hr.ai.use', 'hr.ai.manage', 'hr.analytics.view', 'hr.metric.view'],
        ];

        try {
            $permissionModel = 'Spatie\\Permission\\Models\\Permission';
            $roleModel = 'Spatie\\Permission\\Models\\Role';
            if (! class_exists($permissionModel) || ! class_exists($roleModel)) {
                return;
            }
            foreach ($permissions as $permission) {
                $permissionModel::findOrCreate($permission, 'web');
            }
            foreach ($roles as $role => $rolePermissions) {
                $instance = $roleModel::findOrCreate($role, 'web');
                $instance->syncPermissions($rolePermissions);
            }
            // Peran eksekutif: HR Director melihat seluruh domain HRD.
            $directorRole = $roleModel::findOrCreate('HR_DIRECTOR', 'web');
            $directorRole->syncPermissions($permissionModel::all());
            $principalRole = $roleModel::findOrCreate('PRINCIPAL', 'web');
            $principalRole->syncPermissions(['hr.strategic.view', 'hr.analytics.view', 'hr.metric.view',
                'hr.talent.view', 'hr.skills.view', 'hr.retention.view', 'hr.ai.use']);
            $foundationRole = $roleModel::findOrCreate('FOUNDATION_ADMIN', 'web');
            $foundationRole->syncPermissions($permissionModel::all());

            if ($admin = $roleModel::where('name', 'HR_ADMIN')->first()) {
                $admin->givePermissionTo(array_merge(['hr.strategic.view', 'hr.analytics.view', 'hr.ai.use'], $permissions));
            }
            if ($director = $roleModel::where('name', 'HR_DIRECTOR')->first()) {
                $director->givePermissionTo(['hr.strategic.view', 'hr.strategic.manage', 'hr.analytics.view', 'hr.metric.view', 'hr.ai.use', 'hr.ai.manage', 'hr.risk.view']);
            }
            if ($manager = $roleModel::where('name', 'MANAGER')->first()) {
                $manager->givePermissionTo(['hr.talent.view', 'hr.skills.view', 'hr.ai.use']);
            }
            if ($employee = $roleModel::where('name', 'EMPLOYEE')->first()) {
                $employee->givePermissionTo(['hr.ai.use']);
            }
        } catch (Throwable) {
            // Peran/izin opsional: modul tetap berjalan tanpa Spatie.
        }
    }

    private function seedSkillTaxonomy(): void
    {
        $technology = HrSkillDomain::query()->updateOrCreate(['code' => 'TECHNOLOGY'], [
            'name' => 'Technology', 'description' => 'Keterampilan teknologi & sistem', 'sort_order' => 1, 'is_active' => true,
        ]);
        HrSkillDomain::query()->updateOrCreate(['code' => 'TECHNOLOGY_PROGRAMMING'], [
            'name' => 'Programming', 'parent_id' => $technology->getKey(), 'sort_order' => 2, 'is_active' => true,
        ]);
        $academic = HrSkillDomain::query()->updateOrCreate(['code' => 'ACADEMIC'], [
            'name' => 'Academic', 'description' => 'Keterampilan akademik & pengajaran', 'sort_order' => 3, 'is_active' => true,
        ]);
        HrSkillDomain::query()->updateOrCreate(['code' => 'ACADEMIC_TEACHING'], [
            'name' => 'Teaching', 'parent_id' => $academic->getKey(), 'sort_order' => 4, 'is_active' => true,
        ]);

        foreach ([
            ['code' => 'TECH-PROG', 'name' => 'Programming', 'domain_id' => $technology->getKey()],
            ['code' => 'TECH-CLOUD', 'name' => 'Cloud & Infrastructure', 'domain_id' => $technology->getKey()],
            ['code' => 'TECH-AI', 'name' => 'AI & Data', 'domain_id' => $technology->getKey()],
            ['code' => 'ACAD-MATHEMATICS', 'name' => 'Mathematics Teaching', 'domain_id' => $academic->getKey()],
            ['code' => 'ACAD-ENGLISH', 'name' => 'English Teaching', 'domain_id' => $academic->getKey()],
            ['code' => 'ACAD-LEADERSHIP', 'name' => 'Academic Leadership', 'domain_id' => $academic->getKey()],
        ] as $category) {
            HrSkillCategory::query()->updateOrCreate(['code' => $category['code']], $category + ['is_active' => true]);
        }
    }

    private function seedMetrics(): void
    {
        $engine = app(MetricEngine::class);
        $employee = ['table' => 'hr_employees'];
        $definitions = [
            ['code' => 'HEADCOUNT', 'name' => 'Headcount Aktif', 'category' => 'WORKFORCE', 'formula_type' => 'COUNT',
                'numerator' => $employee + ['operation' => 'COUNT', 'filters' => ['is_active' => true], 'point_in_time' => true],
                'unit' => 'orang', 'dimensions' => ['ORGANIZATION', 'DEPARTMENT', 'POSITION', 'LOCATION', 'WORKER_TYPE']],
            ['code' => 'HEADCOUNT_GROWTH', 'name' => 'Pertumbuhan Headcount', 'category' => 'WORKFORCE', 'formula_type' => 'PERCENT',
                'numerator' => $employee + ['operation' => 'COUNT', 'period_column' => 'hire_date'],
                'denominator' => $employee + ['operation' => 'COUNT', 'filters' => ['is_active' => true], 'point_in_time' => true],
                'unit' => '%', 'period_basis' => 'PERIOD'],
            ['code' => 'OPEN_POSITIONS', 'name' => 'Posisi Terbuka', 'category' => 'WORKFORCE', 'formula_type' => 'COUNT',
                'numerator' => ['table' => 'hr_job_openings', 'operation' => 'COUNT', 'filters' => ['status' => 'OPEN']], 'unit' => 'lowongan'],
            ['code' => 'TURNOVER_RATE', 'name' => 'Tingkat Turnover', 'category' => 'RETENTION', 'formula_type' => 'PERCENT',
                'numerator' => $employee + ['operation' => 'COUNT', 'period_column' => 'termination_date'],
                'denominator' => $employee + ['operation' => 'COUNT', 'filters' => ['is_active' => true], 'point_in_time' => true],
                'unit' => '%', 'period_basis' => 'PERIOD', 'sensitivity' => 'CONFIDENTIAL'],
            ['code' => 'VOLUNTARY_TURNOVER', 'name' => 'Turnover Sukarela', 'category' => 'RETENTION', 'formula_type' => 'PERCENT',
                'numerator' => $employee + ['operation' => 'COUNT', 'period_column' => 'termination_date', 'filters' => ['exit_reason_code' => 'RESIGN']],
                'denominator' => $employee + ['operation' => 'COUNT', 'filters' => ['is_active' => true], 'point_in_time' => true],
                'unit' => '%', 'period_basis' => 'PERIOD'],
            ['code' => 'COST_PER_EMPLOYEE', 'name' => 'Biaya per Pegawai', 'category' => 'COST', 'formula_type' => 'RATIO',
                'numerator' => ['table' => 'hr_payroll_items', 'operation' => 'SUM', 'column' => 'net_amount'],
                'denominator' => $employee + ['operation' => 'COUNT', 'filters' => ['is_active' => true], 'point_in_time' => true],
                'unit' => 'IDR', 'data_classification' => 'CONFIDENTIAL', 'sensitivity' => 'SALARY'],
            ['code' => 'PAYROLL_TOTAL', 'name' => 'Total Payroll', 'category' => 'COST', 'formula_type' => 'SUM',
                'numerator' => ['table' => 'hr_payroll_items', 'operation' => 'SUM', 'column' => 'net_amount', 'period_column' => 'created_at'],
                'unit' => 'IDR', 'period_basis' => 'PERIOD', 'data_classification' => 'CONFIDENTIAL', 'sensitivity' => 'SALARY'],
            ['code' => 'ABSENTEEISM_RATE', 'name' => 'Tingkat Ketidakhadiran', 'category' => 'ATTENDANCE', 'formula_type' => 'PERCENT',
                'numerator' => ['table' => 'hr_attendance_records', 'operation' => 'COUNT', 'filters' => ['status' => 'ABSENT'], 'period_column' => 'date'],
                'denominator' => ['table' => 'hr_attendance_records', 'operation' => 'COUNT', 'period_column' => 'date'],
                'unit' => '%', 'period_basis' => 'PERIOD'],
            ['code' => 'LATE_RATE', 'name' => 'Tingkat Keterlambatan', 'category' => 'ATTENDANCE', 'formula_type' => 'PERCENT',
                'numerator' => ['table' => 'hr_attendance_records', 'operation' => 'COUNT', 'filters' => ['status' => 'LATE'], 'period_column' => 'date'],
                'denominator' => ['table' => 'hr_attendance_records', 'operation' => 'COUNT', 'period_column' => 'date'],
                'unit' => '%', 'period_basis' => 'PERIOD'],
            ['code' => 'OVERTIME_MINUTES', 'name' => 'Lembur Terverifikasi', 'category' => 'ATTENDANCE', 'formula_type' => 'SUM',
                'numerator' => ['table' => 'hr_overtime_records', 'operation' => 'SUM', 'column' => 'verified_minutes', 'filters' => ['status' => 'VERIFIED'], 'period_column' => 'date'],
                'unit' => 'menit', 'period_basis' => 'PERIOD'],
            ['code' => 'PERFORMANCE_AVERAGE', 'name' => 'Rata-rata Kinerja', 'category' => 'PERFORMANCE', 'formula_type' => 'AVERAGE',
                'numerator' => ['table' => 'hr_performance_reviews', 'operation' => 'AVG', 'column' => 'overall_score'], 'unit' => 'skor'],
            ['code' => 'HIGH_PERFORMER_RATE', 'name' => 'Proporsi High Performer', 'category' => 'PERFORMANCE', 'formula_type' => 'PERCENT',
                'numerator' => ['table' => 'hr_talent_review_results', 'operation' => 'COUNT', 'filters' => ['nine_box_code' => 'HIGH_HIGH']],
                'denominator' => ['table' => 'hr_talent_review_results', 'operation' => 'COUNT'], 'unit' => '%'],
            ['code' => 'HIGH_POTENTIAL_RATE', 'name' => 'Proporsi High Potential', 'category' => 'TALENT', 'formula_type' => 'PERCENT',
                'numerator' => ['table' => 'hr_talent_review_results', 'operation' => 'COUNT', 'filters' => ['potential_rating' => 4]],
                'denominator' => ['table' => 'hr_talent_review_results', 'operation' => 'COUNT'], 'unit' => '%'],
            ['code' => 'SKILL_COVERAGE', 'name' => 'Cakupan Keterampilan', 'category' => 'SKILLS', 'formula_type' => 'PERCENT',
                'numerator' => ['table' => 'hr_employee_skills', 'operation' => 'COUNT_DISTINCT', 'column' => 'employee_id'],
                'denominator' => $employee + ['operation' => 'COUNT', 'filters' => ['is_active' => true], 'point_in_time' => true], 'unit' => '%'],
            ['code' => 'CRITICAL_SKILL_GAP', 'name' => 'Gap Keterampilan Kritis', 'category' => 'SKILLS', 'formula_type' => 'SUM',
                'numerator' => ['table' => 'hr_skill_forecasts', 'operation' => 'SUM', 'column' => 'gap', 'period_column' => 'period'],
                'unit' => 'orang', 'period_basis' => 'PERIOD'],
            ['code' => 'TRAINING_COMPLETION', 'name' => 'Penyelesaian Pelatihan', 'category' => 'LEARNING', 'formula_type' => 'PERCENT',
                'numerator' => ['table' => 'hr_training_completions', 'operation' => 'COUNT', 'period_column' => 'completed_at'],
                'denominator' => ['table' => 'hr_training_assignments', 'operation' => 'COUNT'], 'unit' => '%', 'period_basis' => 'PERIOD'],
            ['code' => 'INTERNAL_MOBILITY_RATE', 'name' => 'Mobilitas Internal', 'category' => 'MOBILITY', 'formula_type' => 'PERCENT',
                'numerator' => ['table' => 'hr_internal_job_applications', 'operation' => 'COUNT', 'period_column' => 'created_at'],
                'denominator' => $employee + ['operation' => 'COUNT', 'filters' => ['is_active' => true], 'point_in_time' => true],
                'unit' => '%', 'period_basis' => 'PERIOD'],
            ['code' => 'PROMOTION_RATE', 'name' => 'Tingkat Promosi Internal', 'category' => 'MOBILITY', 'formula_type' => 'PERCENT',
                'numerator' => ['table' => 'hr_internal_job_applications', 'operation' => 'COUNT', 'filters' => ['status' => 'HIRED']],
                'denominator' => ['table' => 'hr_internal_job_applications', 'operation' => 'COUNT'], 'unit' => '%'],
            ['code' => 'SUCCESSION_COVERAGE', 'name' => 'Cakupan Suksesi', 'category' => 'SUCCESSION', 'formula_type' => 'PERCENT',
                'numerator' => ['table' => 'hr_succession_candidates', 'operation' => 'COUNT_DISTINCT', 'column' => 'plan_id'],
                'denominator' => ['table' => 'hr_critical_positions', 'operation' => 'COUNT', 'filters' => ['status' => 'ACTIVE']], 'unit' => '%'],
            ['code' => 'READY_NOW_RATE', 'name' => 'Kandidat Siap Sekarang', 'category' => 'SUCCESSION', 'formula_type' => 'PERCENT',
                'numerator' => ['table' => 'hr_succession_candidates', 'operation' => 'COUNT', 'filters' => ['readiness' => 'READY_NOW']],
                'denominator' => ['table' => 'hr_succession_candidates', 'operation' => 'COUNT'], 'unit' => '%'],
            ['code' => 'ENGAGEMENT_SCORE', 'name' => 'Skor Engagement', 'category' => 'ENGAGEMENT', 'formula_type' => 'AVERAGE',
                'numerator' => ['table' => 'hr_engagement_snapshots', 'operation' => 'AVG', 'column' => 'value', 'filters' => ['metric' => 'ENGAGEMENT']],
                'unit' => 'skor'],
            ['code' => 'SURVEY_PARTICIPATION', 'name' => 'Partisipasi Survei', 'category' => 'ENGAGEMENT', 'formula_type' => 'PERCENT',
                'numerator' => ['table' => 'hr_engagement_snapshots', 'operation' => 'SUM', 'column' => 'respondents'],
                'denominator' => $employee + ['operation' => 'COUNT', 'filters' => ['is_active' => true], 'point_in_time' => true], 'unit' => '%'],
        ];

        foreach ($definitions as $definition) {
            $engine->define($definition);
        }
    }

    private function seedSemanticLayer(): void
    {
        $entities = [
            'EMPLOYEE' => ['name' => 'Pegawai', 'model' => 'App\\Modules\\Hrd\\Models\\HrEmployee',
                'filters' => ['is_active', 'organization_id', 'department_id', 'position_id', 'location_id', 'worker_type'],
                'dimensions' => ['organization_id', 'department_id', 'position_id', 'location_id', 'worker_type'],
                'metrics' => ['headcount', 'turnover', 'cost'], 'sensitivity' => 'INTERNAL', 'permission' => 'hr.employee.view',
                'fields' => ['id', 'employee_number', 'organization_id', 'department_id', 'position_id', 'location_id',
                    'worker_type', 'employment_type', 'hire_date', 'termination_date', 'is_active']],
            'POSITION' => ['name' => 'Posisi', 'model' => 'App\\Modules\\Hrd\\Models\\HrPosition',
                'filters' => ['organization_id', 'department_id', 'status', 'is_critical'], 'dimensions' => ['organization_id', 'department_id'],
                'metrics' => ['open_positions'], 'sensitivity' => 'INTERNAL', 'permission' => 'hr.position.view',
                'fields' => ['id', 'code', 'title', 'organization_id', 'department_id', 'authorized_headcount', 'filled_headcount', 'is_critical', 'status']],
            'PAYROLL_ITEM' => ['name' => 'Item Payroll', 'model' => 'App\\Modules\\Hrd\\Strategic\\..',
                'filters' => ['employee_id'], 'dimensions' => [], 'metrics' => ['payroll_total'], 'sensitivity' => 'RESTRICTED',
                'permission' => 'hr.payroll.view', 'fields' => ['id', 'employee_id', 'payroll_run_id', 'base_amount', 'net_amount', 'overtime_amount']],
            'ATTENDANCE' => ['name' => 'Presensi', 'model' => 'App\\Modules\\Hrd\\Workforce\\Models\\HrAttendanceRecord',
                'filters' => ['status', 'employee_id'], 'dimensions' => ['status'], 'metrics' => ['absenteeism', 'late_rate'],
                'sensitivity' => 'INTERNAL', 'permission' => 'hr.attendance.view', 'fields' => ['id', 'employee_id', 'date', 'status', 'worked_minutes', 'late_minutes']],
            'LEAVE_REQUEST' => ['name' => 'Permohonan Cuti', 'model' => 'App\\Modules\\Hrd\\Leave\\Models\\HrLeaveRequest',
                'filters' => ['status', 'employee_id'], 'dimensions' => ['status'], 'metrics' => ['absence'],
                'sensitivity' => 'INTERNAL', 'permission' => 'hr.leave.view', 'fields' => ['id', 'employee_id', 'status', 'total_days', 'start_date', 'end_date']],
            'PERFORMANCE_REVIEW' => ['name' => 'Penilaian Kinerja', 'model' => 'App\\Modules\\Hrd\\Performance\\Models\\HrPerformanceReview',
                'filters' => ['employee_id', 'status'], 'dimensions' => ['status'], 'metrics' => ['performance_average'],
                'sensitivity' => 'CONFIDENTIAL', 'permission' => 'hr.performance.view', 'fields' => ['id', 'employee_id', 'status', 'final_score']],
            'EMPLOYEE_SKILL' => ['name' => 'Keterampilan Pegawai', 'model' => 'App\\Modules\\Hrd\\Models\\HrEmployeeSkill',
                'filters' => ['employee_id', 'skill_id', 'verified'], 'dimensions' => ['proficiency_level_code'],
                'metrics' => ['skill_coverage'], 'sensitivity' => 'INTERNAL', 'permission' => 'hr.skill.view',
                'fields' => ['id', 'employee_id', 'skill_id', 'proficiency_level_code', 'verified']],
            'SKILL_FORECAST' => ['name' => 'Prakiraan Keterampilan', 'model' => 'Skill Forecast',
                'filters' => ['skill_code', 'organization_id'], 'dimensions' => ['skill_code'], 'metrics' => ['critical_skill_gap'],
                'sensitivity' => 'INTERNAL', 'permission' => 'hr.skills.view', 'fields' => ['id', 'skill_code', 'organization_id', 'period', 'current_supply', 'future_demand', 'gap']],
            'CRITICAL_POSITION' => ['name' => 'Posisi Kritis', 'model' => 'Critical Position',
                'filters' => ['status'], 'dimensions' => ['criticality'], 'metrics' => ['succession_coverage'],
                'sensitivity' => 'CONFIDENTIAL', 'permission' => 'hr.succession.view', 'fields' => ['id', 'position_id', 'criticality', 'status', 'incumbent_employee_id']],
            'SUCCESSION_CANDIDATE' => ['name' => 'Kandidat Suksesi', 'model' => 'Succession Candidate',
                'filters' => ['plan_id', 'readiness', 'status'], 'dimensions' => ['readiness'], 'metrics' => ['ready_now_rate'],
                'sensitivity' => 'CONFIDENTIAL', 'permission' => 'hr.succession.view', 'fields' => ['id', 'plan_id', 'employee_id', 'candidate_type', 'readiness', 'readiness_score']],
            'TALENT_REVIEW_RESULT' => ['name' => 'Hasil Tinjauan Talenta', 'model' => 'Talent Review Result',
                'filters' => ['nine_box_code', 'readiness'], 'dimensions' => ['nine_box_code'], 'metrics' => ['high_potential_rate'],
                'sensitivity' => 'CONFIDENTIAL', 'permission' => 'hr.talent.view', 'fields' => ['id', 'employee_id', 'performance_rating', 'potential_rating', 'nine_box_code', 'readiness']],
            'RETENTION_SIGNAL' => ['name' => 'Sinyal Retensi', 'model' => 'Retention Signal',
                'filters' => ['signal_level', 'status'], 'dimensions' => ['signal_level'], 'metrics' => ['retention_risk'],
                'sensitivity' => 'CONFIDENTIAL', 'permission' => 'hr.retention.view',
                'fields' => ['id', 'employee_id', 'signal_level', 'retention_score', 'period', 'status']],
        ];

        foreach ($entities as $code => $entity) {
            $model = HrSemanticEntity::query()->updateOrCreate(['code' => $code], [
                'name' => $entity['name'], 'source_model' => $entity['model'], 'description' => $entity['name'].' untuk lapisan semantik',
                'allowed_filters' => $entity['filters'], 'allowed_dimensions' => $entity['dimensions'],
                'allowed_metrics' => $entity['metrics'], 'sensitivity' => $entity['sensitivity'],
                'requires_permission' => $entity['permission'], 'is_active' => true,
            ]);
            foreach ($entity['fields'] as $field) {
                HrSemanticField::query()->updateOrCreate(
                    ['entity_code' => $model->code, 'field' => $field],
                    [
                        'label' => ucwords(str_replace('_', ' ', $field)), 'data_type' => 'string',
                        'is_filterable' => in_array($field, $entity['filters'], true),
                        'is_dimension' => in_array($field, $entity['dimensions'], true),
                        'is_metric' => false, 'sensitivity' => $entity['sensitivity'],
                        'permission' => $entity['permission'], 'source_field' => $field,
                    ]
                );
            }
        }
    }

    private function seedCatalog(): void
    {
        $catalog = [
            ['code' => 'DATA-EMPLOYEE', 'dataset' => 'EMPLOYEE', 'name' => 'Data Pegawai', 'definition' => 'Master pegawai aktif (Phase 1).',
                'tables' => ['hr_employees'], 'sensitivity' => 'INTERNAL', 'classification' => 'INTERNAL', 'roles' => ['HR_ADMIN', 'HR_DIRECTOR', 'MANAGER']],
            ['code' => 'DATA-POSITION', 'dataset' => 'POSITION', 'name' => 'Data Posisi', 'definition' => 'Struktur posisi & formasi.',
                'tables' => ['hr_positions'], 'sensitivity' => 'INTERNAL', 'classification' => 'INTERNAL', 'roles' => ['HR_ADMIN', 'HR_DIRECTOR']],
            ['code' => 'DATA-PAYROLL', 'dataset' => 'PAYROLL', 'name' => 'Data Payroll', 'definition' => 'Item payroll final (Phase 2).',
                'tables' => ['hr_payroll_items'], 'sensitivity' => 'RESTRICTED', 'classification' => 'RESTRICTED', 'roles' => ['PAYROLL_ADMIN', 'HR_DIRECTOR']],
            ['code' => 'DATA-ATTENDANCE', 'dataset' => 'ATTENDANCE', 'name' => 'Data Presensi', 'definition' => 'Catatan presensi & lembur.',
                'tables' => ['hr_attendance_records', 'hr_overtime_records'], 'sensitivity' => 'INTERNAL', 'classification' => 'INTERNAL', 'roles' => ['TIME_ADMIN', 'MANAGER']],
            ['code' => 'DATA-SKILLS', 'dataset' => 'SKILLS', 'name' => 'Data Keterampilan', 'definition' => 'Keterampilan pegawai + bukti.',
                'tables' => ['hr_employee_skills', 'hr_skill_evidence'], 'sensitivity' => 'INTERNAL', 'classification' => 'INTERNAL', 'roles' => ['HR_ADMIN', 'LND_ADMIN', 'MANAGER']],
            ['code' => 'DATA-PERFORMANCE', 'dataset' => 'PERFORMANCE', 'name' => 'Data Kinerja', 'definition' => 'Penilaian & tujuan.',
                'tables' => ['hr_performance_reviews'], 'sensitivity' => 'CONFIDENTIAL', 'classification' => 'CONFIDENTIAL', 'roles' => ['HR_ADMIN', 'PERFORMANCE_ADMIN', 'MANAGER']],
            ['code' => 'DATA-LEARNING', 'dataset' => 'LEARNING', 'name' => 'Data Pembelajaran', 'definition' => 'Penugasan & penyelesaian pelatihan.',
                'tables' => ['hr_training_assignments', 'hr_training_completions'], 'sensitivity' => 'INTERNAL', 'classification' => 'INTERNAL', 'roles' => ['LND_ADMIN', 'MANAGER']],
            ['code' => 'DATA-TALENT', 'dataset' => 'TALENT', 'name' => 'Data Talenta', 'definition' => 'Kumpulan talenta, tinjauan, potensi.',
                'tables' => ['hr_talent_pools', 'hr_talent_review_results'], 'sensitivity' => 'CONFIDENTIAL', 'classification' => 'CONFIDENTIAL', 'roles' => ['TALENT_ADMIN', 'HR_DIRECTOR']],
            ['code' => 'DATA-PLAN', 'dataset' => 'WORKFORCE_PLAN', 'name' => 'Rencana Tenaga Kerja', 'definition' => 'Permintaan, pasokan, gap, skenario.',
                'tables' => ['hr_workforce_plans', 'hr_workforce_demand', 'hr_workforce_gaps'], 'sensitivity' => 'CONFIDENTIAL', 'classification' => 'CONFIDENTIAL', 'roles' => ['HR_STRATEGIST']],
        ];

        foreach ($catalog as $entry) {
            $entry['classification'] = $entry['classification'] ?? 'INTERNAL';
            HrDataCatalog::query()->updateOrCreate(['code' => $entry['code']], [
                'name' => $entry['name'], 'dataset' => $entry['dataset'], 'owner_role' => 'HR_DIRECTOR',
                'steward_role' => 'PEOPLE_ANALYST', 'definition' => $entry['definition'],
                'source_tables' => $entry['tables'], 'refresh_frequency' => 'DAILY',
                'sensitivity' => $entry['sensitivity'], 'access_roles' => $entry['roles'],
                'retention_policy' => 'Sesuai kebijakan retensi HRD (minimal 5 tahun).',
                'classification' => $entry['classification'], 'last_reviewed_at' => now(),
            ]);
        }

        foreach ([
            ['code' => 'DQ-EMPLOYEE-NUMBER', 'dataset' => 'EMPLOYEE', 'rule' => 'COMPLETENESS', 'severity' => 'HIGH'],
            ['code' => 'DQ-POSITION-CODE', 'dataset' => 'POSITION', 'rule' => 'UNIQUENESS', 'severity' => 'MEDIUM'],
            ['code' => 'DQ-PAYROLL-EMPLOYEE', 'dataset' => 'PAYROLL', 'rule' => 'CONSISTENCY', 'severity' => 'CRITICAL'],
            ['code' => 'DQ-SKILL-LINK', 'dataset' => 'SKILLS', 'rule' => 'COMPLETENESS', 'severity' => 'MEDIUM'],
        ] as $rule) {
            HrDataQualityRule::query()->updateOrCreate(['code' => $rule['code']], [
                'dataset' => $rule['dataset'], 'rule_type' => $rule['rule'],
                'expression' => 'Kolom kunci terisi pada dataset '.$rule['dataset'],
                'severity' => $rule['severity'], 'owner_role' => 'PEOPLE_ANALYST', 'status' => 'ACTIVE',
            ]);
        }
    }

    private function seedOpportunityTypes(): void
    {
        $marketplace = app(TalentMarketplaceService::class);
        foreach ([
            ['code' => 'PERMANENT_JOB', 'name' => 'Posisi Tetap', 'category' => 'JOB', 'duration_days' => 365],
            ['code' => 'INTERNAL_JOB', 'name' => 'Lowongan Internal', 'category' => 'JOB', 'duration_days' => 180],
            ['code' => 'PROJECT', 'name' => 'Proyek', 'category' => 'PROJECT', 'duration_days' => 90],
            ['code' => 'GIG', 'name' => 'Gig / Tugas Pendek', 'category' => 'GIG', 'duration_days' => 30],
            ['code' => 'MENTORING', 'name' => 'Mentoring', 'category' => 'MENTORING', 'duration_days' => 180],
            ['code' => 'COMMITTEE', 'name' => 'Komite / Panitia', 'category' => 'COMMITTEE', 'duration_days' => 60],
            ['code' => 'TEMPORARY_ASSIGNMENT', 'name' => 'Penugasan Sementara', 'category' => 'ASSIGNMENT', 'duration_days' => 90],
            ['code' => 'STRETCH_ASSIGNMENT', 'name' => 'Stretch Assignment', 'category' => 'ASSIGNMENT', 'duration_days' => 120],
            ['code' => 'CROSS_CAMPUS', 'name' => 'Penugasan Lintas Kampus', 'category' => 'ASSIGNMENT', 'duration_days' => 180],
            ['code' => 'VOLUNTEER', 'name' => 'Kegiatan Sukarela', 'category' => 'COMMITTEE', 'duration_days' => 30],
        ] as $type) {
            $marketplace->defineOpportunityType($type + ['allocation_default' => 20, 'is_paid' => false]);
        }
    }

    private function seedForecastModels(): void
    {
        $forecast = app(ForecastService::class);
        foreach ([
            ['code' => 'FCT-HEADCOUNT', 'name' => 'Prakiraan Headcount', 'purpose' => 'HEADCOUNT', 'method' => 'HISTORICAL_TREND'],
            ['code' => 'FCT-ATTRITION', 'name' => 'Prakiraan Attrition', 'purpose' => 'ATTRITION', 'method' => 'MOVING_AVERAGE'],
            ['code' => 'FCT-COST', 'name' => 'Prakiraan Biaya Tenaga Kerja', 'purpose' => 'COST', 'method' => 'BUSINESS_ASSUMPTION'],
            ['code' => 'FCT-HIRING', 'name' => 'Prakiraan Perekrutan', 'purpose' => 'HIRING', 'method' => 'SEASONALITY'],
            ['code' => 'FCT-RETIREMENT', 'name' => 'Prakiraan Pensiun', 'purpose' => 'RETIREMENT', 'method' => 'BUSINESS_ASSUMPTION'],
        ] as $model) {
            $forecast->defineModel($model + ['features' => ['hire_date', 'termination_date'], 'metrics' => ['mae' => null]]);
        }
    }

    private function seedAi(): void
    {
        HrAiProvider::query()->updateOrCreate(['code' => 'INTERNAL'], [
            'name' => 'Internal Reasoning Provider', 'provider_type' => 'ENTERPRISE', 'data_region' => 'LOCAL',
            'is_private' => true, 'approved_for_sensitive' => true, 'status' => 'ACTIVE', 'priority' => 1,
            'config' => ['mode' => 'rule_based_with_tools', 'note' => 'Bawaan: tanpa panggilan jaringan; seluruh jawaban dari data HR.'],
        ]);
        HrAiProvider::query()->updateOrCreate(['code' => 'OPENAI'], [
            'name' => 'OpenAI', 'provider_type' => 'OPENAI', 'data_region' => 'US',
            'is_private' => false, 'approved_for_sensitive' => false, 'status' => 'CONFIGURED', 'priority' => 10,
            'credential_ref' => 'env:OPENAI_API_KEY',
        ]);
        HrAiProvider::query()->updateOrCreate(['code' => 'LOCAL_LLM'], [
            'name' => 'Local Model', 'provider_type' => 'LOCAL', 'is_private' => true,
            'approved_for_sensitive' => true, 'status' => 'AVAILABLE', 'priority' => 5,
        ]);

        foreach ([
            ['code' => 'internal-small', 'provider' => 'INTERNAL', 'name' => 'Internal Small', 'type' => 'SMALL', 'limit' => 'INTERNAL', 'latency' => 'FAST'],
            ['code' => 'internal-reasoning', 'provider' => 'INTERNAL', 'name' => 'Internal Reasoning', 'type' => 'REASONING', 'limit' => 'CONFIDENTIAL', 'latency' => 'STANDARD'],
            ['code' => 'private-confidential', 'provider' => 'LOCAL_LLM', 'name' => 'Private Confidential', 'type' => 'REASONING', 'limit' => 'RESTRICTED', 'latency' => 'SLOW'],
        ] as $model) {
            HrAiModel::query()->updateOrCreate(['code' => $model['code']], [
                'provider_code' => $model['provider'], 'name' => $model['name'],
                'model_type' => $model['type'], 'data_classification_limit' => $model['limit'],
                'latency_class' => $model['latency'], 'context_window' => 32000,
                'input_cost_per_1k' => 0, 'output_cost_per_1k' => 0, 'is_active' => true,
            ]);
        }

        $agents = [
            ['code' => 'employee-agent', 'name' => 'Employee Agent', 'group' => 'EMPLOYEE', 'tools' => ['get_employee', 'get_leave_balance', 'get_attendance', 'get_training'], 'permissions' => ['hr.ai.use']],
            ['code' => 'recruitment-agent', 'name' => 'Recruitment Agent', 'group' => 'RECRUITMENT', 'tools' => ['get_position', 'get_employee', 'get_skills'], 'permissions' => ['hr.ai.use', 'hr.recruitment.view']],
            ['code' => 'workforce-agent', 'name' => 'Workforce Agent', 'group' => 'WORKFORCE', 'tools' => ['get_workforce_plan', 'get_workforce_analysis', 'get_metrics'], 'permissions' => ['hr.ai.use', 'hr.strategic.view']],
            ['code' => 'payroll-agent', 'name' => 'Payroll Agent', 'group' => 'PAYROLL', 'tools' => ['get_payroll', 'get_employee', 'get_metrics'], 'permissions' => ['hr.ai.use', 'hr.payroll.view']],
            ['code' => 'performance-agent', 'name' => 'Performance Agent', 'group' => 'PERFORMANCE', 'tools' => ['get_employee', 'get_metrics'], 'permissions' => ['hr.ai.use', 'hr.performance.view']],
            ['code' => 'learning-agent', 'name' => 'Learning Agent', 'group' => 'LEARNING', 'tools' => ['get_training', 'get_skills'], 'permissions' => ['hr.ai.use', 'hr.skills.view']],
            ['code' => 'talent-agent', 'name' => 'Talent Agent', 'group' => 'TALENT', 'tools' => ['get_employee', 'get_position', 'get_talent', 'get_skills'], 'permissions' => ['hr.ai.use', 'hr.talent.view']],
            ['code' => 'analytics-agent', 'name' => 'Analytics Agent', 'group' => 'ANALYTICS', 'tools' => ['get_metrics'], 'permissions' => ['hr.ai.use', 'hr.analytics.view']],
            ['code' => 'succession-agent', 'name' => 'Succession Agent', 'group' => 'SUCCESSION', 'tools' => ['get_succession', 'get_employee'], 'permissions' => ['hr.ai.use', 'hr.succession.view']],
            ['code' => 'hr-service-agent', 'name' => 'HR Service Agent', 'group' => 'HR_SERVICE', 'tools' => ['get_employee', 'create_hr_request', 'create_training_request'], 'permissions' => ['hr.ai.use']],
        ];
        foreach ($agents as $agent) {
            HrAiAgent::query()->updateOrCreate(['code' => $agent['code']], [
                'name' => $agent['name'], 'agent_group' => $agent['group'],
                'description' => 'Agent '.$agent['group'].' untuk HRD Phase 3',
                'system_prompt' => 'Gunakan hanya alat terdaftar, hormati scope & izin, jangan mengarang data, dan selalu sertakan bukti.',
                'allowed_tools' => $agent['tools'], 'required_permissions' => $agent['permissions'],
                'model_code' => 'internal-reasoning', 'max_tokens' => 2000,
                'requires_human_review' => true, 'status' => 'ACTIVE', 'version' => 1,
            ]);
        }

        $tools = [
            ['code' => 'get_employee', 'name' => 'Ambil data pegawai', 'group' => 'READ', 'permission' => 'hr.employee.view', 'sensitivity' => 'INTERNAL', 'sources' => ['hr_employees']],
            ['code' => 'get_team', 'name' => 'Ambil tim bawahan', 'group' => 'READ', 'permission' => 'hr.employee.view', 'sensitivity' => 'INTERNAL', 'sources' => ['hr_employees']],
            ['code' => 'get_leave_balance', 'name' => 'Ambil saldo cuti', 'group' => 'READ', 'permission' => 'hr.leave.view', 'sensitivity' => 'INTERNAL', 'sources' => ['hr_leave_balances']],
            ['code' => 'get_attendance', 'name' => 'Ambil presensi', 'group' => 'READ', 'permission' => 'hr.attendance.view', 'sensitivity' => 'INTERNAL', 'sources' => ['hr_attendance_records']],
            ['code' => 'get_payroll', 'name' => 'Ambil payroll', 'group' => 'READ', 'permission' => 'hr.payroll.view', 'sensitivity' => 'RESTRICTED', 'sources' => ['hr_payroll_items']],
            ['code' => 'get_skills', 'name' => 'Ambil keterampilan', 'group' => 'READ', 'permission' => 'hr.skill.view', 'sensitivity' => 'INTERNAL', 'sources' => ['hr_employee_skills']],
            ['code' => 'get_training', 'name' => 'Ambil pelatihan', 'group' => 'READ', 'permission' => 'hr.training.view', 'sensitivity' => 'INTERNAL', 'sources' => ['hr_training_completions']],
            ['code' => 'get_position', 'name' => 'Ambil posisi', 'group' => 'READ', 'permission' => 'hr.position.view', 'sensitivity' => 'INTERNAL', 'sources' => ['hr_positions']],
            ['code' => 'get_workforce_plan', 'name' => 'Ambil rencana tenaga kerja', 'group' => 'ANALYTICS', 'permission' => 'hr.strategic.view', 'sensitivity' => 'CONFIDENTIAL', 'sources' => ['hr_workforce_plans']],
            ['code' => 'get_workforce_analysis', 'name' => 'Analisis kebutuhan tenaga kerja', 'group' => 'ANALYTICS', 'permission' => 'hr.strategic.view', 'sensitivity' => 'CONFIDENTIAL', 'sources' => ['hr_employees', 'academic']],
            ['code' => 'get_talent', 'name' => 'Ambil segmentasi talenta', 'group' => 'ANALYTICS', 'permission' => 'hr.talent.view', 'sensitivity' => 'CONFIDENTIAL', 'sources' => ['hr_talent_review_results']],
            ['code' => 'get_succession', 'name' => 'Ambil cakupan suksesi', 'group' => 'ANALYTICS', 'permission' => 'hr.succession.view', 'sensitivity' => 'CONFIDENTIAL', 'sources' => ['hr_succession_candidates']],
            ['code' => 'get_metrics', 'name' => 'Ambil metrik kanonik', 'group' => 'ANALYTICS', 'permission' => 'hr.metric.view', 'sensitivity' => 'INTERNAL', 'sources' => ['hr_metric_definitions']],
            ['code' => 'create_hr_request', 'name' => 'Draf permintaan HR', 'group' => 'WRITE', 'permission' => 'hr.ai.use', 'sensitivity' => 'INTERNAL', 'is_write' => true, 'confirm' => true],
            ['code' => 'create_training_request', 'name' => 'Draf permintaan pelatihan', 'group' => 'WRITE', 'permission' => 'hr.ai.use', 'sensitivity' => 'INTERNAL', 'is_write' => true, 'confirm' => true],
            ['code' => 'create_report', 'name' => 'Buat laporan (lapisan semantik)', 'group' => 'ANALYTICS', 'permission' => 'hr.analytics.view', 'sensitivity' => 'INTERNAL'],
        ];
        foreach ($tools as $tool) {
            HrAiTool::query()->updateOrCreate(['code' => $tool['code']], [
                'name' => $tool['name'], 'tool_group' => $tool['group'],
                'description' => $tool['name'], 'handler' => $tool['code'],
                'input_schema' => ['type' => 'object'], 'output_schema' => ['sources' => $tool['sources'] ?? []],
                'required_permission' => $tool['permission'], 'sensitivity' => $tool['sensitivity'],
                'is_write' => (bool) ($tool['is_write'] ?? false),
                'requires_confirmation' => (bool) ($tool['confirm'] ?? false), 'is_active' => true,
            ]);
            HrAiToolPermission::query()->updateOrCreate(
                ['tool_code' => $tool['code'], 'principal_type' => 'ROLE', 'principal_reference' => 'AI_AGENT'],
                ['scope' => 'SELF', 'allowed' => true, 'field_masking' => []]
            );
        }

        HrAiPolicy::query()->updateOrCreate(['code' => 'AI-HR-POLICY'], [
            'name' => 'Kebijakan AI HR', 'purpose' => 'Menjaga AI tetap dapat diaudit, adil, dan tidak menggantikan manusia dalam keputusan berdampak.',
            'rules' => [
                'no_source_no_answer' => true,
                'tools_must_be_registered' => true,
                'field_masking_before_provider' => true,
                'human_review_for_writes' => true,
                'no_automatic_termination' => true,
            ],
            'applies_to' => ['COPILOT', 'AGENTS', 'ANALYTICS'],
            'data_classification_limit' => 'CONFIDENTIAL', 'requires_human_review' => true,
            'prohibited_actions' => config('hrd_phase3.ai.prohibited_actions', []),
            'status' => 'ACTIVE', 'effective_from' => now()->toDateString(), 'owner_role' => 'HR_DIRECTOR',
        ]);
    }

    private function seedDashboards(): void
    {
        $dashboards = [
            ['code' => 'EXECUTIVE', 'name' => 'Executive HR Dashboard', 'audience' => 'EXECUTIVE', 'scope' => 'GLOBAL',
                'widgets' => [['HEADCOUNT', 'METRIC'], ['TURNOVER_RATE', 'TREND'], ['COST_PER_EMPLOYEE', 'METRIC'],
                    ['SUCCESSION_COVERAGE', 'COMPARISON'], ['CRITICAL_SKILL_GAP', 'METRIC'], ['TRAINING_COMPLETION', 'METRIC']]],
            ['code' => 'HR_DIRECTOR', 'name' => 'HR Director Dashboard', 'audience' => 'HR_DIRECTOR', 'scope' => 'GLOBAL',
                'widgets' => [['HEADCOUNT', 'METRIC'], ['ABSENTEEISM_RATE', 'TREND'], ['PERFORMANCE_AVERAGE', 'TREND'],
                    ['INTERNAL_MOBILITY_RATE', 'METRIC'], ['HIGH_POTENTIAL_RATE', 'METRIC']]],
            ['code' => 'PRINCIPAL', 'name' => 'Principal Dashboard', 'audience' => 'PRINCIPAL', 'scope' => 'ORGANIZATION',
                'widgets' => [['HEADCOUNT', 'METRIC'], ['ABSENTEEISM_RATE', 'METRIC'], ['TRAINING_COMPLETION', 'METRIC']]],
            ['code' => 'FOUNDATION', 'name' => 'Yayasan / Board View', 'audience' => 'FOUNDATION', 'scope' => 'GLOBAL',
                'widgets' => [['HEADCOUNT', 'COMPARISON'], ['PAYROLL_TOTAL', 'COMPARISON'], ['TURNOVER_RATE', 'COMPARISON']]],
            ['code' => 'PEOPLE_ANALYTICS', 'name' => 'People Analytics Workspace', 'audience' => 'HR_MANAGER', 'scope' => 'GLOBAL',
                'widgets' => [['HEADCOUNT', 'METRIC'], ['TURNOVER_RATE', 'TREND'], ['SKILL_COVERAGE', 'HEATMAP'],
                    ['ENGAGEMENT_SCORE', 'TREND'], ['PAYROLL_TOTAL', 'TABLE']]],
        ];
        foreach ($dashboards as $dashboard) {
            $row = HrAnalyticsDashboard::query()->updateOrCreate(['code' => $dashboard['code']], [
                'name' => $dashboard['name'], 'audience' => $dashboard['audience'],
                'scope_type' => $dashboard['scope'], 'status' => 'ACTIVE',
                'default_filters' => ['period' => now()->format('Y-m')], 'layout' => ['columns' => 3],
            ]);
            foreach ($dashboard['widgets'] as $index => $widget) {
                HrAnalyticsWidget::query()->updateOrCreate(
                    ['dashboard_id' => $row->getKey(), 'code' => strtolower($dashboard['code'].'-'.$widget[0])],
                    ['title' => $widget[0], 'widget_type' => $widget[1], 'metric_code' => $widget[0],
                        'dimensions' => [], 'query_plan' => ['metric' => $widget[0], 'periods' => 6],
                        'position' => $index + 1, 'size' => $widget[1] === 'TREND' ? 'LARGE' : 'MEDIUM']
                );
            }
        }
    }

    private function seedJourneys(): void
    {
        $journeys = app(JourneyAutomationService::class);
        $journeys->defineJourney([
            'code' => 'JOURNEY-ONBOARDING', 'name' => 'Onboarding Pegawai Baru', 'journey_type' => 'ONBOARDING',
            'trigger_event' => 'EmployeeCreated', 'target_audience' => ['NEW_HIRE'],
            'stages' => [
                ['code' => 'DOCUMENTS', 'name' => 'Kelengkapan dokumen', 'type' => 'TASK', 'offset_days' => 1],
                ['code' => 'ORIENTATION', 'name' => 'Orientasi & perkenalan', 'type' => 'TASK', 'offset_days' => 3],
                ['code' => 'BUDDY', 'name' => 'Penunjukan buddy/mentor', 'type' => 'TASK', 'offset_days' => 5],
                ['code' => 'MANDATORY_TRAINING', 'name' => 'Pelatihan wajib', 'type' => 'TRAINING', 'offset_days' => 14],
                ['code' => 'PROBATION_REVIEW', 'name' => 'Tinjauan masa percobaan', 'type' => 'CHECKPOINT', 'offset_days' => 90],
            ],
        ]);
        $journeys->defineJourney([
            'code' => 'JOURNEY-PROMOTION', 'name' => 'Transisi Promosi', 'journey_type' => 'PROMOTION',
            'trigger_event' => 'EmployeePromoted', 'target_audience' => ['PROMOTED'],
            'stages' => [
                ['code' => 'POSITION_UPDATE', 'name' => 'Pembaruan posisi (Phase 1 transaction)', 'type' => 'WORKFLOW', 'offset_days' => 0],
                ['code' => 'LEARNING_PLAN', 'name' => 'Rencana pembelajaran baru', 'type' => 'TRAINING', 'offset_days' => 14],
                ['code' => 'NEW_GOALS', 'name' => 'Tujuan kinerja baru', 'type' => 'GOAL', 'offset_days' => 30],
                ['code' => 'MANAGER_NOTIFY', 'name' => 'Notifikasi atasan', 'type' => 'NOTIFICATION', 'offset_days' => 1],
            ],
        ]);
        $journeys->defineJourney([
            'code' => 'JOURNEY-OFFBOARDING', 'name' => 'Offboarding', 'journey_type' => 'OFFBOARDING',
            'trigger_event' => 'EmployeeTerminated', 'target_audience' => ['LEAVER'],
            'stages' => [
                ['code' => 'CLEARANCE', 'name' => 'Clearance aset & akses', 'type' => 'TASK', 'offset_days' => 1],
                ['code' => 'EXIT_INTERVIEW', 'name' => 'Wawancara keluar', 'type' => 'CHECKPOINT', 'offset_days' => 3],
                ['code' => 'FINAL_PAYROLL', 'name' => 'Payroll final', 'type' => 'WORKFLOW', 'offset_days' => 7],
            ],
        ]);
    }

    private function seedAutomationRules(): void
    {
        if (! Schema::hasTable('hr_rules')) {
            return;
        }
        DB::table('hr_rules')->updateOrInsert(['code' => 'AUT-CERT-EXPIRY'], [
            'name' => 'Sertifikasi wajib mendekati kedaluwarsa',
            'purpose' => 'hrd3.automation',
            'description' => 'Bila sertifikasi wajib akan berakhir dalam 90 hari: beri tahu pegawai & atasan, buat tugas pelatihan, dan naikkan peringatan HR.',
            'match_mode' => 'ALL', 'priority' => 10,
            'action_type' => json_encode(['all' => [['field' => 'event', 'operator' => '=', 'value' => 'certification.expiring'], ['field' => 'mandatory', 'operator' => '=', 'value' => true]]]),
            'action_payload' => json_encode([
                ['type' => 'NOTIFY_MANAGER', 'params' => []],
                ['type' => 'CREATE_TRAINING_TASK', 'params' => []],
                ['type' => 'CREATE_HR_ALERT', 'params' => ['type' => 'certification.expiring', 'severity' => 'MEDIUM', 'title' => 'Sertifikasi wajib akan berakhir']],
            ]),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_rules')->updateOrInsert(['code' => 'AUT-PROMOTION-JOURNEY'], [
            'name' => 'Promosi memicu perjalanan transisi',
            'purpose' => 'hrd3.automation',
            'description' => 'Promosi memicu journey transisi: posisi diperbarui, rencana pembelajaran, tujuan baru, notifikasi atasan.',
            'match_mode' => 'ALL', 'priority' => 20,
            'action_type' => json_encode(['all' => [['field' => 'event', 'operator' => '=', 'value' => 'employee.promoted']]]),
            'action_payload' => json_encode([
                ['type' => 'CREATE_GOALS', 'params' => []],
                ['type' => 'NOTIFY_MANAGER', 'params' => []],
            ]),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedKnowledge(): void
    {
        app(AiIntelligenceService::class)->indexDocument([
            'code' => 'KB-LEAVE-POLICY',
            'title' => 'Kebijakan Cuti Pegawai',
            'category' => 'POLICY',
            'sensitivity' => 'INTERNAL',
            'content' => <<<'TEXT'
Cuti tahunan diberikan 12 hari kerja per tahun untuk pegawai tetap dan diakrualkan setiap bulan.

Cuti dapat diajukan paling lambat 3 hari kerja sebelum tanggal mulai, kecuali keadaan darurat dengan persetujuan atasan langsung.

Saldo cuti yang tidak terpakai dapat dibawa ke tahun berikutnya maksimum 5 hari dan hangus pada akhir kuartal pertama.

Cuti tanpa upah hanya dapat disetujui oleh HR Director dan tidak menambah saldo cuti tahunan.
TEXT,
        ]);
        app(AiIntelligenceService::class)->indexDocument([
            'code' => 'KB-ATTENDANCE-POLICY',
            'title' => 'Aturan Presensi & Lembur',
            'category' => 'POLICY',
            'sensitivity' => 'INTERNAL',
            'content' => <<<'TEXT'
Presensi dicatat melalui perangkat yang terdaftar: fingerprint, QR, atau aplikasi mobile dengan geofence.

Keterlambatan lebih dari 15 menit dicatat sebagai LATE dan dihitung dalam laporan kedisiplinan, bukan langsung menjadi potongan gaji.

Lembur harus disetujui sebelumnya dan diverifikasi berdasarkan jam kerja nyata; pembayaran lembur mengikuti pengali jenis hari dan dihitung di payroll.

Shift malam yang melewati tengah malam dihitung sebagai satu shift utuh sesuai jadwal yang berlaku.
TEXT,
        ]);
    }
}
