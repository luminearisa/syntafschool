<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('HRD_PHASE3_ENABLED', true),
    'rate_limit' => (int) env('HRD_PHASE3_RATE_LIMIT', 90),

    /*
    |--------------------------------------------------------------------------
    | Perencanaan tenaga kerja strategis
    |--------------------------------------------------------------------------
    */
    'planning' => [
        'horizons' => [
            'MONTHLY' => 1, 'QUARTERLY' => 3, 'ANNUAL' => 12, 'THREE_YEARS' => 36, 'FIVE_YEARS' => 60, 'CUSTOM' => 0,
        ],
        'default_horizon' => 'ANNUAL',
        'scenario_types' => [
            'BASELINE', 'GROWTH', 'EXPANSION', 'CONTRACTION', 'HIRING_FREEZE', 'BUDGET_REDUCTION',
            'NEW_CAMPUS', 'NEW_PROGRAM', 'DIGITAL_TRANSFORMATION', 'AUTOMATION', 'CUSTOM',
        ],
        'gap_types' => ['HEADCOUNT', 'SKILL', 'COST', 'CAPACITY', 'LEADERSHIP', 'LOCATION'],
        'severity_scale' => ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'],
        'currency' => env('HRD_PHASE3_CURRENCY', 'IDR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Skills intelligence
    |--------------------------------------------------------------------------
    */
    'skills' => [
        'levels' => ['NONE' => 0, 'BEGINNER' => 1, 'INTERMEDIATE' => 2, 'ADVANCED' => 3, 'EXPERT' => 4],
        'requirement_types' => ['REQUIRED', 'PREFERRED', 'CRITICAL', 'EMERGING'],
        'criticality' => ['CRITICAL', 'STRATEGIC', 'EMERGING', 'SCARCE', 'BUSINESS_CRITICAL', 'REGULATORY', 'NORMAL'],
        // Bobot keyakinan per jenis bukti (0–100). Bukan kebenaran absolut:
        // selalu disajikan bersama evidence.
        'confidence' => [
            'SELF' => 40, 'AI_SUGGESTED' => 45, 'SYSTEM_INFERRED' => 55, 'MANAGER' => 70,
            'ASSESSMENT' => 85, 'CERTIFICATION' => 95,
        ],
        'decay' => [
            // bulan sebelum sebuah keterampilan dianggap stale/expired tanpa bukti baru
            'stale_after_months' => 18, 'expired_after_months' => 36, 'emerging_window_months' => 12,
        ],
        'lifeycle' => ['CURRENT', 'EMERGING', 'STALE', 'EXPIRED', 'OBSOLETE'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Succession & talent
    |--------------------------------------------------------------------------
    */
    'succession' => [
        'criticality' => ['CRITICAL', 'BUSINESS_CRITICAL', 'LEADERSHIP_CRITICAL', 'SKILL_CRITICAL', 'REGULATORY_CRITICAL'],
        'readiness_levels' => ['READY_NOW', 'READY_LT_1Y', 'READY_1_2Y', 'READY_3Y_PLUS', 'NOT_READY'],
        'candidate_types' => ['PRIMARY', 'SECONDARY', 'EMERGENCY', 'TALENT_POOL'],
        'min_successors' => 2,
        'readiness_weights' => [
            'skills' => 0.25, 'performance' => 0.20, 'competency' => 0.15, 'experience' => 0.15,
            'aspiration' => 0.10, 'development' => 0.10, 'certification' => 0.05,
        ],
        'readiness_thresholds' => ['READY_NOW' => 85, 'READY_LT_1Y' => 70, 'READY_1_2Y' => 55, 'READY_3Y_PLUS' => 40],
    ],

    'marketplace' => [
        'min_match_score' => 40.0,
        'bands' => ['STRONG_MATCH' => 80, 'MATCH' => 60, 'PARTIAL' => 40],
        'weights' => [
            'skill' => 0.35, 'experience' => 0.15, 'interest' => 0.10, 'career_goal' => 0.10,
            'location' => 0.05, 'availability' => 0.10, 'certification' => 0.05, 'performance' => 0.10,
        ],
        'max_allocation_percent' => 100.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analitik & pelaporan
    |--------------------------------------------------------------------------
    */
    'analytics' => [
        'privacy_threshold' => (int) env('HRD_PHASE3_PRIVACY_THRESHOLD', 5),
        'cache_ttl' => 300,
        'snapshot_granularity' => ['DAILY', 'WEEKLY', 'MONTHLY', 'QUARTERLY', 'ANNUAL'],
        'refresh' => [
            'transactional' => 'REALTIME', 'dashboard' => 'NEAR_REALTIME',
            'strategic' => 'DAILY', 'forecast' => 'SCHEDULED', 'ai' => 'CONTROLLED',
        ],
        'domains' => ['WORKFORCE', 'RETENTION', 'COMPENSATION', 'ATTENDANCE', 'PERFORMANCE', 'SKILLS', 'LEARNING', 'MOBILITY', 'SUCCESSION', 'ENGAGEMENT'],
    ],

    'forecasting' => [
        'methods' => ['HISTORICAL_TREND', 'MOVING_AVERAGE', 'SEASONALITY', 'BUSINESS_ASSUMPTION', 'ML'],
        'default_horizon_months' => 12,
        'moving_average_window' => 3,
        'confidence_floor' => 0.4,
        'purposes' => ['HEADCOUNT', 'ATTRITION', 'HIRING', 'COST', 'SKILL', 'RETIREMENT', 'DEMAND'],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI: gateway, agent, tool, governance
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'enabled' => (bool) env('HRD_PHASE3_AI_ENABLED', true),
        'default_provider' => env('HRD_PHASE3_AI_PROVIDER', 'INTERNAL'),
        'no_source_no_answer' => true,
        'max_tool_calls' => 8,
        'require_human_review_for_writes' => true,
        'cost_tracking' => true,
        'max_prompt_chars' => 4000,
        // Perutean model: pertanyaan sederhana tidak perlu model reasoning,
        // data sensitif hanya boleh diproses model privat yang disetujui.
        'routing' => [
            'SIMPLE' => 'SMALL', 'STANDARD' => 'SMALL', 'COMPLEX' => 'REASONING', 'SENSITIVE' => 'PRIVATE',
        ],
        // Kolom yang di-mask kecuali pemanggil memiliki izinnya (field-level security).
        'field_permissions' => [
            'salary_amount' => 'hr.payroll.view', 'net_amount' => 'hr.payroll.view',
            'base_amount' => 'hr.payroll.view', 'bank_account' => 'hr.payroll.view',
            'medical_notes' => 'hr.medical.view', 'disciplinary_notes' => 'hr.discipline.view',
            'performance_confidential' => 'hr.performance.view',
        ],
        'blocked_fields' => [
            'salary_amount', 'net_amount', 'base_amount', 'nik', 'medical_notes', 'disciplinary_notes',
            'performance_confidential', 'bank_account',
        ],
        'prohibited_actions' => [
            'TERMINATE_EMPLOYEE', 'REJECT_CANDIDATE_AUTOMATICALLY', 'REDUCE_SALARY', 'DEMOTE_EMPLOYEE',
            'DENY_LEAVE', 'DELETE_EMPLOYEE_RECORD',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrasi luar (semuanya lewat kontrak; implementasi bawaan null-safe)
    |--------------------------------------------------------------------------
    */
    'integrations' => [
        'academic' => ['enabled' => true, 'students_per_teacher_target' => 18, 'default_weekly_teaching_hours' => 24],
        'boarding' => ['enabled' => true, 'santri_per_musyrif_target' => 25],
        'clinic' => ['enabled' => true, 'visits_per_staff_per_day' => 30],
        'finance' => ['enabled' => true],
        'asset' => ['enabled' => true],
        'survey' => ['enabled' => true],
        'helpdesk' => ['enabled' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Keamanan: scope & klasifikasi data
    |--------------------------------------------------------------------------
    */
    'security' => [
        'scopes' => ['SELF', 'TEAM', 'DEPARTMENT', 'ORGANIZATION', 'GLOBAL'],
        'classifications' => ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'],
        'manager_can_see_team_salary' => false,
        'analytics_respects_scope' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Binding kontrak → implementasi (diisi bertahap; lihat Domain/Contracts)
    |--------------------------------------------------------------------------
    */
    'bindings' => [
        \App\Modules\Hrd\Strategic\Domain\Contracts\WorkforcePlanningProviderInterface::class
            => \App\Modules\Hrd\Strategic\Workforce\Services\WorkforcePlanningService::class,
        \App\Modules\Hrd\Strategic\Domain\Contracts\WorkforceForecastProviderInterface::class
            => \App\Modules\Hrd\Strategic\Forecasting\Services\ForecastService::class,
        \App\Modules\Hrd\Strategic\Domain\Contracts\SkillsIntelligenceProviderInterface::class
            => \App\Modules\Hrd\Strategic\Skills\Services\SkillsIntelligenceService::class,
        \App\Modules\Hrd\Strategic\Domain\Contracts\TalentIntelligenceProviderInterface::class
            => \App\Modules\Hrd\Strategic\Talent\Services\TalentIntelligenceService::class,
        \App\Modules\Hrd\Strategic\Domain\Contracts\SuccessionProviderInterface::class
            => \App\Modules\Hrd\Strategic\Succession\Services\SuccessionService::class,
        \App\Modules\Hrd\Strategic\Domain\Contracts\TalentMarketplaceProviderInterface::class
            => \App\Modules\Hrd\Strategic\Talent\Services\TalentMarketplaceService::class,
        \App\Modules\Hrd\Strategic\Domain\Contracts\PeopleAnalyticsProviderInterface::class
            => \App\Modules\Hrd\Strategic\Analytics\Services\PeopleAnalyticsService::class,
        \App\Modules\Hrd\Strategic\Domain\Contracts\CompensationIntelligenceProviderInterface::class
            => \App\Modules\Hrd\Strategic\Compensation\Services\CompensationIntelligenceService::class,
        \App\Modules\Hrd\Strategic\Domain\Contracts\RetentionAnalyticsProviderInterface::class
            => \App\Modules\Hrd\Strategic\Retention\Services\RetentionAnalyticsService::class,
        \App\Modules\Hrd\Strategic\Domain\Contracts\AIHRProviderInterface::class
            => \App\Modules\Hrd\Strategic\Ai\Services\AIOrchestratorService::class,
        \App\Modules\Hrd\Strategic\Domain\Contracts\HRRecommendationProviderInterface::class
            => \App\Modules\Hrd\Strategic\Ai\Services\AIOrchestratorService::class,
    ],
];
