<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mode operasi LMS
    |--------------------------------------------------------------------------
    |
    | `false` (default) → Integrated Mode: LMS memakai entity ERP/SIAKAD yang
    | sudah ada (User, Student, Employee, SchoolClass, Subject, AcademicYear).
    | Tidak ada tabel User/Student baru yang dibuat.
    |
    | `true` → Standalone Mode: LMS berjalan mandiri dengan identitas sendiri
    | (`lms_users`, `lms_learners`, `lms_guardians`). Kontrak domain yang sama
    | (`UserProviderInterface`, `StudentProviderInterface`, …) dipenuhi oleh
    | implementasi lain, sehingga Application/Http tidak perlu berubah.
    |
    */
    'standalone' => env('LMS_STANDALONE', false),

    /*
    |--------------------------------------------------------------------------
    | Prefix & penamaan route
    |--------------------------------------------------------------------------
    */
    'prefix' => 'lms',
    'public_prefix' => 'lms',   // endpoint publik: /api/v1/lms/public/*
    'web_public_path' => '/learn',

    /*
    |--------------------------------------------------------------------------
    | Unggahan berkas
    |--------------------------------------------------------------------------
    |
    | Berkas TIDAK disimpan di database. Menumpang disk Laravel yang sudah
    | dikonfigurasi ERP (`config/filesystems.php`), sehingga hak akses berkas
    | mengikuti satu sistem penyimpanan yang sama.
    |
    */
    'uploads' => [
        'disk' => env('LMS_UPLOAD_DISK', 'local'),
        'directory' => 'lms',
        'max_size_kb' => (int) env('LMS_UPLOAD_MAX_KB', 20480),
        'allowed_mimes' => [
            'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
            'mp3', 'wav', 'm4a', 'ogg',
            'mp4', 'webm', 'mov', 'txt', 'csv',
        ],
        // Video besar sebaiknya dititipkan ke CDN/streaming, bukan disajikan
        // langsung dari application server (§13).
        'external_video_hosts' => ['youtube.com', 'youtu.be', 'vimeo.com', 'drive.google.com'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Perilaku pembelajaran
    |--------------------------------------------------------------------------
    */
    'enrollment' => [
        // Student boleh mendaftar sendiri pada course PUBLIC/INSTITUTION.
        // Course ACADEMIC tetap ditentukan SIAKAD (§16).
        'self_enrollment' => env('LMS_SELF_ENROLLMENT', true),
        // Saat KRS/enrollment akademik dibatalkan, riwayat belajar tidak
        // dihapus — statusnya SUSPENDED (§17).
        'suspend_on_academic_cancel' => true,
    ],

    'progress' => [
        // Item tanpa flag `is_required` tidak dihitung dalam persentase.
        'count_optional_items' => false,
    ],

    'parent' => [
        // Wali hanya melihat nilai bila policy sekolah mengizinkan (§3).
        'can_view_grades' => env('LMS_PARENT_VIEW_GRADES', true),
        'can_view_feedback' => env('LMS_PARENT_VIEW_FEEDBACK', true),
    ],

    'guest' => [
        // Guest hanya melihat course ber-visibility PUBLIC, dan hanya bagian
        // yang ditandai dapat dipratinjau (preview) oleh guru.
        'preview_items' => env('LMS_GUEST_PREVIEW', true),
    ],


    /*
    |--------------------------------------------------------------------------
    | Gradebook & penilaian (Phase 3)
    |--------------------------------------------------------------------------
    |
    | Default gradebook baru. Semua nilai dapat diubah per course, jadi tidak ada
    | kebijakan sekolah yang dipaksa dari kode.
    |
    */
    'gradebook' => [
        'grading_method' => env('LMS_GRADING_METHOD', 'PERCENTAGE'),
        'aggregation_method' => env('LMS_GRADE_AGGREGATION', 'WEIGHTED_MEAN'),
        // PENDING = nilai kosong TIDAK dianggap 0 (§9).
        'missing_grade_policy' => env('LMS_MISSING_GRADE_POLICY', 'PENDING'),
        // STRICT mewajibkan total bobot 100%; NORMALIZE membagi dengan totalnya.
        'weight_policy' => env('LMS_WEIGHT_POLICY', 'NORMALIZE'),
        'extra_credit_policy' => env('LMS_EXTRA_CREDIT_POLICY', 'CAP'),
        'maximum_grade' => (float) env('LMS_MAXIMUM_GRADE', 100),
        'passing_grade' => (float) env('LMS_PASSING_GRADE', 70),
    ],

    /*
    |--------------------------------------------------------------------------
    | Learning outcome (Phase 3)
    |--------------------------------------------------------------------------
    |
    | Ambang batas capaian default; tiap outcome dapat menimpanya sendiri (§28).
    |
    */
    'outcome' => [
        'thresholds' => [
            'developing' => (float) env('LMS_OUTCOME_DEVELOPING', 60),
            'achieved' => (float) env('LMS_OUTCOME_ACHIEVED', 75),
            'mastered' => (float) env('LMS_OUTCOME_MASTERED', 90),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ekspor nilai (§38)
    |--------------------------------------------------------------------------
    |
    | CSV selalu tersedia. Tambahkan 'xlsx'/'pdf' hanya bila exporter terpasang.
    |
    */
    'export' => [
        'formats' => ['csv'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pemberitahuan
    |--------------------------------------------------------------------------
    */
    /*
    |----------------------------------------------------------------------
    | Provider integrasi (§4) — kosong berarti mengikuti mode yang aktif.
    |----------------------------------------------------------------------
    |
    | Isi hanya bila sekolah memakai implementasi lain (mis. penyimpanan S3,
    | wali dari sistem eksternal). Business logic tidak pernah memeriksa mode:
    | pemilihan provider terjadi sekali di `LmsServiceProvider`.
    |
    */
    'providers' => [
        'parent' => env('LMS_PARENT_PROVIDER'),
        'enrollment' => env('LMS_ENROLLMENT_PROVIDER'),
        'notification' => env('LMS_NOTIFICATION_PROVIDER'),
        'calendar' => env('LMS_CALENDAR_PROVIDER'),
        'files' => env('LMS_FILE_PROVIDER'),
        'attendance' => env('LMS_ATTENDANCE_PROVIDER'),
        'finance' => env('LMS_FINANCE_PROVIDER'),
        'interactive_content' => env('LMS_INTERACTIVE_PROVIDER'),
    ],

    /*
    |----------------------------------------------------------------------
    | Kelayakan keuangan (§8) — LMS tidak memuat aturan Finance.
    |----------------------------------------------------------------------
    |
    | `enabled=false` (bawaan) berarti LMS tidak pernah menanyakan status
    | keuangan. Bila sekolah mengaktifkannya, belajar tetap berjalan
    | (`allow_learning_when_restricted=true`) kecuali sekolah memilih untuk
    | menahan kemampuan tertentu. Kemampuan pada `always_allowed` tidak pernah
    | ditahan, dan tidak ada data belajar yang pernah dihapus.
    |
    */
    'finance' => [
        'enabled' => env('LMS_FINANCE_GATE', false),
        'allow_learning_when_restricted' => env('LMS_ALLOW_LEARNING_WHEN_RESTRICTED', true),
        'grace_days' => (int) env('LMS_FINANCE_GRACE_DAYS', 0),
        'restricted_capabilities' => ['enroll_new_course', 'start_new_attempt'],
        'always_allowed' => ['view', 'continue', 'submit', 'grade', 'certificate', 'history'],
    ],

    /* Audit trail (§23) — immutable, dengan ip & correlation id. */
    'audit' => [
        'enabled' => env('LMS_AUDIT_ENABLED', true),
        'mirror_to_table' => env('LMS_AUDIT_MIRROR', true),
        'retention_days' => (int) env('LMS_AUDIT_RETENTION_DAYS', 1095),
    ],

    /* Report center (§22). */
    'reports' => [
        'allow_rich_formats' => env('LMS_REPORT_RICH_FORMATS', false),
        'sync_row_limit' => (int) env('LMS_REPORT_SYNC_LIMIT', 500),
        'export_retention_days' => (int) env('LMS_REPORT_RETENTION_DAYS', 7),
        'disk' => env('LMS_REPORT_DISK', 'local'),
    ],

    /* Retensi data (§39). Penghapusan hanya berjalan dengan konfirmasi eksplisit. */
    'retention' => [
        'archive_after_days' => (int) env('LMS_ARCHIVE_AFTER_DAYS', 365),
        'submission_keep_days' => (int) env('LMS_SUBMISSION_KEEP_DAYS', 1095),
        'quiz_attempt_keep_days' => (int) env('LMS_QUIZ_ATTEMPT_KEEP_DAYS', 1095),
        'notification_keep_days' => (int) env('LMS_NOTIFICATION_KEEP_DAYS', 180),
        'temporary_file_hours' => (int) env('LMS_TEMP_FILE_HOURS', 24),
    ],

    /* Antrean & otomatisasi (§11). */
    'automation' => [
        'health_refresh_minutes' => (int) env('LMS_HEALTH_REFRESH_MINUTES', 30),
        'due_reminder_days' => (int) env('LMS_DUE_REMINDER_DAYS', 2),
        'overdue_reminder_days' => (int) env('LMS_OVERDUE_REMINDER_DAYS', 3),
        'grade_sync_max_attempts' => (int) env('LMS_GRADE_SYNC_MAX_ATTEMPTS', 5),
        'grade_sync_backoff_minutes' => (int) env('LMS_GRADE_SYNC_BACKOFF_MINUTES', 10),
        'certificate_auto_issue' => env('LMS_CERTIFICATE_AUTO_ISSUE', true),
        'badge_evaluation' => env('LMS_BADGE_AUTO_EVALUATE', true),
    ],

    /* Penyimpanan berkas LMS (§28): visibilitas diatur per berkas. */
    'files' => [
        'disk' => env('LMS_FILES_DISK', 'local'),
        'temporary_lifetime_hours' => (int) env('LMS_TEMP_FILE_HOURS', 24),
        'max_upload_mb' => (int) env('LMS_MAX_UPLOAD_MB', 25),
        'allowed_mimes' => explode(',', (string) env(
            'LMS_ALLOWED_MIMES',
            'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,mp4,mp3,webm,png,jpg,jpeg,webp',
        )),
    ],

    /* Rate limit (§30) — dapat diubah sekolah tanpa deploy. */
    'rate_limits' => [
        'quiz_attempt' => (int) env('LMS_RL_QUIZ_ATTEMPT', 10),
        'quiz_answer' => (int) env('LMS_RL_QUIZ_ANSWER', 120),
        'submission' => (int) env('LMS_RL_SUBMISSION', 20),
        'forum_post' => (int) env('LMS_RL_FORUM_POST', 30),
        'file_upload' => (int) env('LMS_RL_FILE_UPLOAD', 30),
        'certificate_verify' => (int) env('LMS_RL_CERT_VERIFY', 30),
        'search' => (int) env('LMS_RL_SEARCH', 60),
        'public_api' => (int) env('LMS_RL_PUBLIC', 120),
        'scorm_tracking' => (int) env('LMS_RL_SCORM', 300),
        'grade_sync' => (int) env('LMS_RL_GRADE_SYNC', 60),
        'notification' => (int) env('LMS_RL_NOTIFICATION', 60),
        'live_join' => (int) env('LMS_RL_LIVE_JOIN', 30),
        'course_import' => (int) env('LMS_RL_COURSE_IMPORT', 10),
        'report_export' => (int) env('LMS_RL_REPORT_EXPORT', 20),
    ],

    'notifications' => [
        'announcement_channels' => ['database'],
        // Phase 3 — pemberitahuan nilai/banding memakai kanal yang sama (§46).
        'grade_channels' => ['database'],
        // Phase 4 — kelas langsung, sertifikat, badge, dan pengingat tenggat.
        'live_channels' => ['database'],
        'certificate_channels' => ['database'],
        'badge_channels' => ['database'],
        'due_channels' => ['database'],
        'certificate_expiry_channels' => ['database'],

        /*
         * Peta peristiwa → kanal (§10). Peristiwa yang tidak tercantum memakai
         * kanal bawaan enum `NotificationEvent` (in_app). Kanal yang belum
         * punya penyedia (mis. whatsapp) dilaporkan sebagai tidak tersedia,
         * bukan gagal.
         */
        'events' => [
            'COURSE_ENROLLED' => ['in_app'],
            'COURSE_STARTED' => ['in_app'],
            'ACTIVITY_AVAILABLE' => ['in_app'],
            'ACTIVITY_DUE_SOON' => ['in_app', 'email'],
            'ACTIVITY_OVERDUE' => ['in_app', 'email'],
            'ASSIGNMENT_SUBMITTED' => ['in_app'],
            'ASSIGNMENT_GRADED' => ['in_app'],
            'QUIZ_AVAILABLE' => ['in_app'],
            'QUIZ_COMPLETED' => ['in_app'],
            'GRADE_RELEASED' => ['in_app', 'email'],
            'FORUM_REPLY' => ['in_app'],
            'LIVE_SESSION_UPCOMING' => ['in_app'],
            'LIVE_SESSION_STARTED' => ['in_app'],
            'COURSE_COMPLETED' => ['in_app'],
            'CERTIFICATE_ISSUED' => ['in_app', 'email'],
            'BADGE_EARNED' => ['in_app'],
            'LEARNING_AT_RISK' => ['in_app', 'email'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — Learning Path, prasyarat, dan drip content (§1–§4)
    |--------------------------------------------------------------------------
    |
    | Mode default hanya berlaku untuk path baru; guru dapat mengubahnya per
    | course. `enforce_sequential` mematikan penguncian berurutan tanpa
    | menghapus konfigurasi path (berguna saat tahun ajaran berganti).
    |
    */
    'learning_path' => [
        'default_mode' => env('LMS_LEARNING_PATH_MODE', 'FLEXIBLE'),
        'enforce_sequential' => env('LMS_LEARNING_PATH_SEQUENTIAL', true),
    ],

    'drip' => [
        'enabled' => env('LMS_DRIP_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — konten interaktif (§7–§12)
    |--------------------------------------------------------------------------
    |
    | Batas ini melindungi server dari dokumen yang terlalu besar, bukan
    | pembatasan pedagogis. Tipe baru dapat ditambah lewat registry tanpa
    | mengubah konfigurasi ini.
    |
    */
    'interactive' => [
        'versioning' => true,
        'max_slides' => (int) env('LMS_INTERACTIVE_MAX_SLIDES', 100),
        'max_cards' => (int) env('LMS_INTERACTIVE_MAX_CARDS', 500),
        'max_pairs' => (int) env('LMS_INTERACTIVE_MAX_PAIRS', 100),
        'max_timeline_entries' => (int) env('LMS_INTERACTIVE_MAX_TIMELINE', 100),
        'max_interactions_per_video' => (int) env('LMS_INTERACTIVE_MAX_VIDEO_MARKS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — kelas langsung (§13–§17)
    |--------------------------------------------------------------------------
    |
    | Kehadiran default MANUAL karena provider bawaan hanya menyimpan tautan.
    | Klaim kehadiran otomatis hanya boleh muncul bila provider benar-benar
    | mengirim data (lihat LiveMeetingProviderInterface::supportsAttendance()).
    |
    */
    'live' => [
        'default_provider' => env('LMS_LIVE_PROVIDER', 'EXTERNAL'),
        'join_window_minutes' => (int) env('LMS_LIVE_JOIN_WINDOW', 15),
        'join_grace_minutes' => (int) env('LMS_LIVE_JOIN_GRACE', 10),
        'attendance_source' => env('LMS_LIVE_ATTENDANCE_SOURCE', 'MANUAL'),
        'recording_completion_threshold' => (int) env('LMS_LIVE_RECORDING_THRESHOLD', 80),
        'office_hour_slot_minutes' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — sertifikat (§21–§25)
    |--------------------------------------------------------------------------
    */
    'certificate' => [
        'enabled' => env('LMS_CERTIFICATE_ENABLED', true),
        'passing_grade' => (float) env('LMS_CERTIFICATE_PASSING_GRADE', 70),
        'sequence_prefix' => env('LMS_CERTIFICATE_PREFIX', 'CERT'),
        'sequence_padding' => 6,
        'verify_prefix' => 'LMS-CERT',
        'pdf' => [
            'enabled' => env('LMS_CERTIFICATE_PDF', true),
            'disk' => env('LMS_CERTIFICATE_DISK', 'local'),
            'directory' => 'lms/certificates',
            'paper' => 'a4',
            'orientation' => 'landscape',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — gamifikasi (§28–§30)
    |--------------------------------------------------------------------------
    |
    | Gamifikasi selalu opsional dan default NONAKTIF. Leaderboard default OFF
    | dan hanya memakai nama tampilan yang diizinkan privacy setting.
    |
    */
    'gamification' => [
        'enabled' => env('LMS_GAMIFICATION_ENABLED', false),
        'leaderboard_enabled' => env('LMS_LEADERBOARD_ENABLED', false),
        'leaderboard_scopes' => ['course', 'class'],
        'leaderboard_limit' => 20,
        'display' => env('LMS_LEADERBOARD_DISPLAY', 'name'),   // name | initials | anonymized
        // Bobot poin per peristiwa (§28). Nilai di sini hanya menimpa bobot
        // bawaan `GamificationEventType::defaultPoints()`; menghapus satu baris
        // berarti kembali ke bobot bawaan, bukan berarti poinnya nol.
        'points' => [
            'LESSON_COMPLETED' => (int) env('LMS_XP_LESSON_COMPLETED', 10),
            'ASSIGNMENT_COMPLETED' => (int) env('LMS_XP_ASSIGNMENT_COMPLETED', 20),
            'QUIZ_PASSED' => (int) env('LMS_XP_QUIZ_PASSED', 25),
            'COURSE_COMPLETED' => (int) env('LMS_XP_COURSE_COMPLETED', 100),
            'FORUM_CONTRIBUTION' => (int) env('LMS_XP_FORUM_CONTRIBUTION', 5),
            'LIVE_ATTENDED' => (int) env('LMS_XP_LIVE_ATTENDED', 15),
            'BADGE_AWARDED' => (int) env('LMS_XP_BADGE_AWARDED', 10),
            'STREAK_BONUS' => (int) env('LMS_XP_STREAK_BONUS', 5),
        ],
        'streak' => [
            'enabled' => env('LMS_STREAK_ENABLED', true),
            'definition' => env('LMS_STREAK_DEFINITION', 'activity_completed'),
            'timezone' => env('LMS_STREAK_TIMEZONE', 'Asia/Jakarta'),
            'bonus_per_week' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — ulasan & umpan balik (§31–§32)
    |--------------------------------------------------------------------------
    */
    'reviews' => [
        'enabled' => env('LMS_REVIEW_ENABLED', true),
        'require_enrollment' => true,
        'auto_approve' => env('LMS_REVIEW_AUTO_APPROVE', false),
        'min_completion_percent' => 0,
        'public_display_name' => env('LMS_REVIEW_DISPLAY', 'name'),  // name | initials
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — engagement & kesehatan belajar (§58–§61)
    |--------------------------------------------------------------------------
    |
    | Ambang di bawah hanya menandai aktivitas LMS; tidak ada diagnosis atau
    | klaim kemampuan siswa.
    |
    */
    'engagement' => [
        'enabled' => env('LMS_ENGAGEMENT_ENABLED', true),
        'trend_days' => 14,
        'health' => [
            'inactive_days' => (int) env('LMS_HEALTH_INACTIVE_DAYS', 14),
            'at_risk_overdue' => (int) env('LMS_HEALTH_AT_RISK_OVERDUE', 2),
            'low_completion_percent' => (float) env('LMS_HEALTH_LOW_COMPLETION', 40),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — pesan & sebutan (§33–§35)
    |--------------------------------------------------------------------------
    |
    | `driver` = notification (bawaan) memakai notification center yang sama.
    | Bila ERP nanti punya modul pesan, cukup daftarkan adapter baru.
    |
    */
    'messaging' => [
        'driver' => env('LMS_MESSAGING_DRIVER', 'notification'),
        'teacher_student' => true,
        'teacher_parent' => true,
        'parent_recipients' => env('LMS_MESSAGING_PARENTS', true),
        'max_length' => 2000,
    ],


    /*
    |--------------------------------------------------------------------------
    | Integrasi nilai ke SIAKAD (§40–§42)
    |--------------------------------------------------------------------------
    |
    | `push_grades` sengaja default NONAKTIF: nilai rapor tidak pernah berubah
    | tanpa keputusan eksplisit sekolah. Saat aktif, LMS mengirim nilai akhir ke
    | modul akademik sebagai satu penilaian (bukan laporan hasil belajar baru).
    |
    */
    'integration' => [
        'push_grades' => env('LMS_INTEGRATION_PUSH_GRADES', false),
    ],
];
