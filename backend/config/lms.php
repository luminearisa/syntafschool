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
    | Pemberitahuan
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'announcement_channels' => ['database'],
    ],
];
