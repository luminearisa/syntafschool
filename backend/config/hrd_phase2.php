<?php

// HRD Phase 2: seluruh ambang batas dan pilihan konfigurabel.
// Aturan yang dapat berubah (tarif pajak, skema cuti, kelipatan lembur) hidup di
// tabel berversi (hr_tax_rules, hr_leave_policies, hr_salary_components, hr_rules),
// bukan di dalam kelas layanan.

return [
    'recruitment' => [
        'pipeline' => ['APPLIED', 'SCREENING', 'SHORTLISTED', 'ASSESSMENT', 'INTERVIEW', 'FINAL_INTERVIEW', 'REFERENCE_CHECK', 'OFFER', 'OFFER_ACCEPTED', 'HIRED'],
        'terminal_stages' => ['REJECTED', 'WITHDRAWN', 'HIRED'],
        'blind_scorecards' => true,
        'offer_expiry_days' => 14,
        'interview_reminder_hours' => 24,
        'sources' => ['CAREER_WEBSITE', 'EMPLOYEE_REFERRAL', 'JOB_BOARD', 'SOCIAL_MEDIA', 'RECRUITER', 'CAMPUS', 'AGENCY', 'WALK_IN', 'INTERNAL', 'OTHER'],
    ],
    'attendance' => [
        'providers' => ['manual', 'web', 'mobile', 'qr', 'nfc', 'rfid', 'fingerprint', 'face', 'device_api'],
        'default_provider' => 'manual',
        'geofence_radius_meters' => 150,
        'late_grace_minutes' => 10,
        'early_leave_grace_minutes' => 5,
        'max_shift_hours' => 16,
        'auto_close_hours' => 16,
    ],
    'overtime' => [
        'require_actual_verification' => true,
        'minimum_minutes' => 30,
        'rounding_minutes' => 15,
        'multiplier_workday' => 1.5,
        'multiplier_rest_day' => 2.0,
        'multiplier_holiday' => 2.0,
        'max_hours_per_month' => 60,
        'require_evidence' => false,
    ],
    'leave' => [
        'default_accrual' => 'MONTHLY',
        'carry_forward_expiry_months' => 3,
        'max_concurrent_team_absence_percent' => 40,
        'minimum_notice_days' => 1,
        'require_document_after_days' => 2,
    ],
    'payroll' => [
        'default_period' => 'MONTHLY',
        'working_days_per_month' => 22,
        'working_hours_per_day' => 8,
        'rounding' => 2,
        'separation_of_duties' => true,
        'variance_gross_percent' => 15,
        'variance_net_percent' => 15,
        'variance_headcount' => 5,
        'tax_provider' => 'database',
        'tax_version' => 'ID-2026',
    ],
    'benefits' => [
        'enrollment_windows' => ['ANNUAL', 'NEW_HIRE', 'LIFE_EVENT', 'OPEN'],
        'default_waiting_days' => 0,
        'require_dependent_verification' => true,
    ],
    'performance' => [
        'default_scale' => 'SCALE_1_5',
        'calibration_enabled' => true,
        'anonymous_feedback_allowed' => true,
        'nine_box_performance_low' => 2.5,
        'nine_box_performance_high' => 3.8,
        'nine_box_potential_low' => 2.5,
        'nine_box_potential_high' => 3.8,
        'pip_default_days' => 90,
    ],
    'learning' => [
        'provider' => 'lms',
        'require_manager_approval' => true,
        'budget_check_enabled' => true,
        'skill_update_on_completion' => true,
        'completion_requires_assessment' => false,
    ],
    'talent' => [
        'pools' => ['HIGH_POTENTIAL', 'LEADERSHIP', 'CRITICAL_SKILL', 'FUTURE_PRINCIPAL', 'FUTURE_MANAGER', 'SPECIALIST', 'SUCCESSION_CANDIDATE'],
        'review_requires_performance_data' => true,
    ],
    'ai' => [
        'enabled' => false,
        'provider' => 'null',
        'may_decide_hiring' => false,
        'blocked_fields' => ['salary', 'net_pay', 'performance_rating', 'medical', 'disciplinary', 'candidate_score'],
    ],
    'alerts' => [
        'contract_expiring_days' => 45,
        'certification_expiring_days' => 60,
        'training_overdue_days' => 7,
        'performance_review_due_days' => 14,
        'goal_overdue_days' => 3,
        'offer_expiring_days' => 3,
        'candidate_waiting_days' => 5,
        'overtime_anomaly_hours' => 40,
        'absence_anomaly_days' => 3,
    ],
];
