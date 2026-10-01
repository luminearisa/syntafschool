<?php

use App\Modules\Workflow\Infrastructure\Notifications\EngineNotificationProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | Workflow & Approval Engine
    |--------------------------------------------------------------------------
    |
    | Konfigurasi tunggal untuk seluruh perilaku engine. Nilai di sini adalah
    | **kebijakan default**; setiap definisi workflow dapat menimpanya lewat
    | kolom `settings`/`config` miliknya sendiri. Tidak ada ambang batas bisnis
    | (mis. "> Rp 10 juta ke Direktur") yang boleh di-hard-code di dalam kode
    | engine — semuanya dibaca dari definisi + berkas ini.
    |
    */

    'enabled' => env('WORKFLOW_ENABLED', true),

    // Prefix kode instance, mis. WF-2026-000123.
    'code_prefix' => env('WORKFLOW_CODE_PREFIX', 'WF'),

    // Kalender kerja default untuk perhitungan SLA (lihat workflow_calendars).
    'calendar' => env('WORKFLOW_CALENDAR', 'DEFAULT'),

    'notifications' => [
        // Kanal default bila definisi tidak menentukan sendiri.
        'channels' => ['IN_APP'],
        'queue' => env('WORKFLOW_QUEUE'),
        // Batas percobaan pengiriman sebelum baris ditandai FAILED.
        'max_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | SLA
    |--------------------------------------------------------------------------
    */
    'sla' => [
        // Persentase SLA yang memicu DUE_SOON (mis. 80% dari 48 jam = 38,4 jam).
        'due_soon_percent' => 80,
        // Persentase SLA yang memicu pengingat pertama.
        'reminder_percent' => 50,
        // Kebijakan eskalasi default bila step tidak mengaturnya sendiri.
        'escalation' => [
            'enabled' => true,
            'after_percent' => 100,   // eskalasi ketika SLA terlampaui
            'repeat_percent' => 50,   // ulangi setiap 50% SLA berikutnya
            'max_levels' => 3,
            'action' => 'NOTIFY',     // NOTIFY|REASSIGN|ESCALATE|MARK_OVERDUE
        ],
        // Batas umur (hari) request RETURNED sebelum ditandai EXPIRED.
        'expire_returned_after_days' => (int) env('WORKFLOW_EXPIRE_RETURNED_DAYS', 30),
        // Batas umur (hari) draft yang belum pernah disubmit.
        'expire_drafts_after_days' => (int) env('WORKFLOW_EXPIRE_DRAFT_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kebijakan engine (default, dapat ditimpa per versi workflow)
    |--------------------------------------------------------------------------
    */
    'policies' => [
        // Perilaku ketika tidak ada approver yang memenuhi syarat:
        // FAIL | ESCALATE | USE_FALLBACK | PAUSE. Tidak pernah "lewati diam-diam".
        'empty_approver' => 'FAIL',
        // Default wajib eksplisit: requester tidak boleh menyetujui request sendiri.
        'allow_self_approval' => false,
        // Decision yang mewajibkan komentar.
        'comment_required_on' => ['REJECT', 'RETURN', 'REQUEST_CHANGES', 'SKIP', 'REASSIGN'],
        // Perilaku setelah RETURN: RESUME_FROM_PREVIOUS_STEP | RESTART_WORKFLOW.
        'return_strategy' => 'RESUME_FROM_PREVIOUS_STEP',
        // Batas perpindahan node dalam satu transisi (penjaga loop tak terbatas).
        'max_execution_hops' => 200,
        // Ketika action bisnis gagal: LOG (catat, lanjut) | FAIL (instance FAILED).
        'action_on_failure' => 'LOG',
        // Berapa lama (menit) keputusan boleh diulang dengan idempotency key sama.
        'idempotency_window_minutes' => 1440,
    ],

    /*
    |--------------------------------------------------------------------------
    | Batas laju endpoint workflow
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'module' => 300,
        'write' => 120,
        'decision' => 90,
        'simulate' => 30,
        'analytics' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifikasi
    |--------------------------------------------------------------------------
    | Provider dikunci lewat kontrak NotificationProviderInterface — sekolah
    | dapat menggantinya dengan WhatsApp/email gateway tanpa menyentuh engine.
    */
    'notification_provider' => EngineNotificationProvider::class,

    /*
    |--------------------------------------------------------------------------
    | Kalender kerja
    |--------------------------------------------------------------------------
    */
    'business_calendar' => [
        // Jika false, SLA dihitung sebagai menit berjalan (24/7) — berguna
        // untuk SLA operasional seperti "respon dalam 60 menit".
        'respect_working_hours' => env('WORKFLOW_RESPECT_WORKING_HOURS', true),
        'timezone' => env('WORKFLOW_TIMEZONE', 'Asia/Jakarta'),
        'weekend' => [6, 7],          // 6=Sabtu, 7=Minggu (ISO-8601)
        'work_start' => '08:00',
        'work_end' => '17:00',
    ],
];
