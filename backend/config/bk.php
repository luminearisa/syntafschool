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

    /*
    |--------------------------------------------------------------------------
    | Phase 2 — ambang operasional lanjutan
    |--------------------------------------------------------------------------
    |
    | Semua nilai di bawah adalah **kebijakan**, bukan diagnosis, dan semuanya
    | dapat ditimpa lewat basis data (aturan peringatan dini & kebijakan SLA).
    | Yang ada di sini hanya nilai bawaan supaya instalasi baru berjalan masuk akal
    | tanpa penyetelan awal.
    */

    // Kelompok umur kasus (case aging) dalam hari.
    'aging' => [
        'DAYS_0_7' => (int) env('BK_AGING_0_7', 7),
        'DAYS_8_30' => (int) env('BK_AGING_8_30', 30),
        'DAYS_31_60' => (int) env('BK_AGING_31_60', 60),
        'DAYS_61_90' => (int) env('BK_AGING_61_90', 90),
    ],

    // Isolasi unit sekolah (tenant). Bila mati, modul berperilaku seperti
    // instalasi satu sekolah: tidak ada penyaringan tambahan.
    'tenant' => [
        'enabled' => (bool) env('BK_TENANT_SCOPE', false),
        'default_school_id' => env('BK_TENANT_SCHOOL_ID'),
    ],

    // Batas jumlah peristiwa aturan yang dievaluasi dalam satu kali jalan,
    // supaya perintah terjadwal tidak menghabiskan memori pada data besar.
    'early_warning' => [
        'batch_size' => (int) env('BK_EW_BATCH', 500),
        'evaluation_queue' => env('BK_EW_QUEUE', 'bk-early-warning'),
        'max_indicators_per_student_per_day' => (int) env('BK_EW_MAX_PER_DAY', 5),
        'trend_window_days' => (int) env('BK_EW_TREND_WINDOW', 90),
    ],

    // SLA bawaan (jam) dipakai bila sekolah belum menetapkan kebijakannya.
    'sla' => [
        'warning_fraction' => (float) env('BK_SLA_WARN_FRACTION', 0.75),
        'escalation_enabled' => (bool) env('BK_SLA_ESCALATION', true),
        'management_after_hours' => (int) env('BK_SLA_MANAGEMENT_AFTER', 72),
    ],

    // Kerja latar belakang: ekspor berat & pengiriman notifikasi.
    'queue' => [
        'default' => env('BK_QUEUE', 'bk'),
        'export_disk' => env('BK_EXPORT_DISK', 'local'),
        'export_path' => env('BK_EXPORT_PATH', 'bk/exports'),
        'export_expiry_hours' => (int) env('BK_EXPORT_EXPIRY_HOURS', 24),
        'signed_url_minutes' => (int) env('BK_SIGNED_URL_MINUTES', 10),
    ],

    // Pencarian aman: jumlah maksimum hasil per jenis agar tidak dapat dipakai
    // memanen data secara massal.
    'search' => [
        'max_results_per_type' => (int) env('BK_SEARCH_MAX', 20),
        'log_queries' => (bool) env('BK_SEARCH_LOG', true),
    ],

    // Umpan balik: apakah anonim diizinkan dan apakah konselor boleh melihat
    // umpan balik yang menyebut dirinya.
    'feedback' => [
        'allow_anonymous' => (bool) env('BK_FEEDBACK_ANONYMOUS', true),
        'moderate_comments' => (bool) env('BK_FEEDBACK_MODERATE', true),
        'counselor_sees_own_rating' => (bool) env('BK_FEEDBACK_COUNSELOR_OWN', true),
    ],
];
