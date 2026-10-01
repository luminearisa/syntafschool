<?php

/*
|--------------------------------------------------------------------------
| Modul Student Activity & Extracurricular
|--------------------------------------------------------------------------
|
| Seluruh ambang batas dan kebijakan modul ini dapat diubah tanpa mengubah
| kode: batas jumlah aktivitas, kebijakan bentrok jadwal, kebijakan siswa
| belum membayar, kanal notifikasi, dan lokasi berkas sertifikat.
|
*/

return [
    'limits' => [
        // Batas jumlah aktivitas aktif per siswa. 0 = tanpa batas.
        'max_active_activities' => (int) env('ACTIVITY_MAX_ACTIVE', 3),

        // true  = melampaui batas menggagalkan pendaftaran,
        // false = hanya menjadi peringatan (dapat di-approve pengelola).
        'max_activities_is_hard' => (bool) env('ACTIVITY_MAX_ACTIVE_HARD', true),
    ],

    'conflict' => [
        // Apakah bentrok jadwal menggagalkan pendaftaran atau hanya diperingatkan.
        'block_enrollment' => (bool) env('ACTIVITY_CONFLICT_BLOCK', true),
        'check_academic' => (bool) env('ACTIVITY_CONFLICT_ACADEMIC', true),
        'check_boarding' => (bool) env('ACTIVITY_CONFLICT_BOARDING', true),
        'check_coach' => (bool) env('ACTIVITY_CONFLICT_COACH', true),
    ],

    'attendance' => [
        // Cerminkan kehadiran aktivitas ke modul Attendance (tabel student_attendances).
        'mirror' => (bool) env('ACTIVITY_ATTENDANCE_MIRROR', false),
        'warning_after_sessions' => (int) env('ACTIVITY_ATTENDANCE_WARNING_AFTER', 3),
    ],

    'progress' => [
        // Di atas ambang ini progres siswa dianggap "berkembang".
        'progressing_threshold' => (float) env('ACTIVITY_PROGRESS_THRESHOLD', 60),
    ],

    'consent' => [
        'expiry_days' => (int) env('ACTIVITY_CONSENT_EXPIRY_DAYS', 14),
    ],

    'finance' => [
        // false = modul hanya mencatat permintaan tagihan (bendahara memutuskan),
        // true  = permintaan langsung menjadi draf tagihan di modul Keuangan.
        'create_invoices' => (bool) env('ACTIVITY_FINANCE_INVOICES', false),
        'due_days' => (int) env('ACTIVITY_FINANCE_DUE_DAYS', 14),
        'grace_days' => (int) env('ACTIVITY_FINANCE_GRACE_DAYS', 7),

        // Kebijakan siswa yang belum melunasi: ALLOW | WARN | RESTRICT.
        'unpaid_policy' => env('ACTIVITY_UNPAID_POLICY', 'WARN'),
    ],

    'facility' => [
        'block_on_conflict' => (bool) env('ACTIVITY_FACILITY_BLOCK', true),
    ],

    'notification' => [
        'channels' => array_filter(explode(',', (string) env('ACTIVITY_NOTIFICATION_CHANNELS', 'database'))),
    ],

    'documents' => [
        'disk' => env('ACTIVITY_DOCUMENT_DISK', 'public'),
    ],

    'certificates' => [
        'view' => env('ACTIVITY_CERTIFICATE_VIEW', 'activity::certificate'),
        'path' => env('ACTIVITY_CERTIFICATE_PATH', 'activity-certificates'),
    ],

    'sessions' => [
        // Rentang bawaan pembuatan sesi dari jadwal berulang (hari).
        'generation_horizon_days' => (int) env('ACTIVITY_SESSION_HORIZON', 90),
    ],

    'competition' => [
        // Izinkan pendaftaran lomba meski status bukan REGISTRATION_OPEN.
        'allow_registration_outside_window' => (bool) env('ACTIVITY_COMPETITION_OPEN_ALWAYS', false),
    ],
];
