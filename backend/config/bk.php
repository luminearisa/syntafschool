<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Modul Bimbingan & Konseling (BK)
    |--------------------------------------------------------------------------
    |
    | Ambang di bawah adalah **kebijakan operasional**, bukan diagnosis: nilainya
    | dipakai untuk memunculkan peringatan atau penanda, tidak pernah untuk
    | menyimpulkan kondisi seorang anak. Semuanya dapat diubah tanpa deploy.
    |
    */

    // Batas laju (request per menit per pengguna) untuk endpoint sensitif.
    'rate_limits' => [
        'module' => env('BK_RL_MODULE', 300),
        'search' => env('BK_RL_SEARCH', 120),
        'write' => env('BK_RL_WRITE', 120),
        'report' => env('BK_RL_REPORT', 20),
        'download' => env('BK_RL_DOWNLOAD', 60),
    ],

    // Ambang akademik: dipakai indikator screening & profil dukungan.
    'academic' => [
        'minimum_score' => (float) env('BK_MINIMUM_SCORE', 70),
    ],

    // Ambang poin kedisiplinan (penanda, bukan sanski).
    'discipline' => [
        'warning_points' => (int) env('BK_DISCIPLINE_WARNING', 25),
        'serious_points' => (int) env('BK_DISCIPLINE_SERIOUS', 50),
    ],

    // Tenggat bawaan bila tidak ditentukan pengguna (hari).
    'windows' => [
        'referral_screening_days' => (int) env('BK_REFERRAL_SCREENING_DAYS', 3),
        'case_review_days' => (int) env('BK_CASE_REVIEW_DAYS', 14),
        'follow_up_default_days' => (int) env('BK_FOLLOW_UP_DAYS', 14),
        'consent_expiry_days' => (int) env('BK_CONSENT_EXPIRY_DAYS', 14),
        'appointment_reminder_hours' => (int) env('BK_APPOINTMENT_REMINDER_HOURS', 24),
    ],

    // Batas jumlah kasus aktif bawaan bagi konselor baru.
    'counselor' => [
        'default_max_active_cases' => (int) env('BK_COUNSELOR_MAX_CASES', 25),
        'allow_self_referral' => (bool) env('BK_ALLOW_SELF_REFERRAL', true),
    ],

    // Penyimpanan berkas dokumen kasus (isi berkas; metadata ada di basis data).
    'documents' => [
        'disk' => env('BK_DOCUMENT_DISK', 'local'),
        'path' => env('BK_DOCUMENT_PATH', 'bk/documents'),
        'max_kb' => (int) env('BK_DOCUMENT_MAX_KB', 10240),
    ],

    // Pratinjau notifikasi: matikan untuk menghentikan pengiriman ke pengguna.
    'notifications' => [
        'enabled' => (bool) env('BK_NOTIFICATIONS_ENABLED', true),
        'include_homeroom_teacher' => (bool) env('BK_NOTIFY_HOMEROOM', true),
    ],
];
