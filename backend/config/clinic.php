<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Modul Clinic / UKS
    |--------------------------------------------------------------------------
    |
    | Ambang klinis BUKAN keputusan medis otomatis: nilai di bawah hanya dipakai
    | untuk memunculkan peringatan agar petugas memeriksa lebih dahulu. Semua
    | dapat diubah tanpa deploy karena dimuat dari konfigurasi/env.
    |
    */

    // Batas laju (request per menit per pengguna) untuk endpoint sensitif.
    'rate_limits' => [
        'module' => env('CLINIC_RL_MODULE', 300),
        'search' => env('CLINIC_RL_SEARCH', 120),
        'write' => env('CLINIC_RL_WRITE', 120),
        'report' => env('CLINIC_RL_REPORT', 20),
        'download' => env('CLINIC_RL_DOWNLOAD', 60),
    ],

    // Ambang peringatan tanda vital (peringatan saja, bukan diagnosis).
    'vitals' => [
        'fever_celsius' => (float) env('CLINIC_FEVER_C', 37.5),
        'high_fever_celsius' => (float) env('CLINIC_HIGH_FEVER_C', 39.0),
        'hypothermia_celsius' => (float) env('CLINIC_HYPOTHERMIA_C', 35.0),
        'tachycardia_bpm' => (int) env('CLINIC_TACHYCARDIA', 120),
        'bradycardia_bpm' => (int) env('CLINIC_BRADYCARDIA', 50),
        'tachypnea_rpm' => (int) env('CLINIC_TACHYPNEA', 26),
        'hypoxia_spo2' => (int) env('CLINIC_HYPOXIA_SPO2', 94),
        'hypertensive_systolic' => (int) env('CLINIC_HTN_SYSTOLIC', 140),
        'hypotensive_systolic' => (int) env('CLINIC_HYPOTENSION_SYSTOLIC', 90),
        'severe_pain' => (int) env('CLINIC_SEVERE_PAIN', 7),
    ],

    // Kedaluwarsa obat: berapa hari sebelumnya harus diperingatkan.
    'expiry_warning_days' => (int) env('CLINIC_EXPIRY_WARN_DAYS', 60),

    // Kebijakan dispensing.
    'dispensing' => [
        // Bila true, penyerahan obat boleh melebihi stok bila klinik mengizinkan
        // (mis. obat titipan orang tua). Default: tidak boleh.
        'allow_negative_stock' => (bool) env('CLINIC_ALLOW_NEGATIVE_STOCK', false),
        // Bila true, obat kedaluwarsa ditolak keras.
        'block_expired' => (bool) env('CLINIC_BLOCK_EXPIRED', true),
        // Wajib alasan bila resep tetap diberikan meski ada peringatan alergi.
        'require_override_reason' => (bool) env('CLINIC_REQUIRE_OVERRIDE_REASON', true),
    ],

    // Ambang integritas antrean: batas waktu antar tahap (menit) untuk laporan.
    'queue' => [
        'waiting_warning_minutes' => (int) env('CLINIC_WAIT_WARN', 30),
    ],

    /*
    | Integrasi presensi.
    |
    | Kunjungan ke UKS pada jam sekolah dapat memengaruhi presensi kelas, tetapi
    | presensi adalah data resmi yang dimiliki wali kelas. Karena itu modul ini
    | **tidak menulis** presensi kecuali sekolah mengizinkannya di sini. Bila
    | diaktifkan, baris dibuat berstatus "belum terverifikasi" dan tidak menimpa
    | baris yang sudah ada.
    */
    'attendance' => [
        'auto_mark' => (bool) env('CLINIC_ATTENDANCE_AUTOMARK', false),
    ],

    // Kebijakan privasi notifikasi & portal orang tua.
    'privacy' => [
        // Field yang boleh tampil di portal orang tua.
        'parent_visible_notes' => (bool) env('CLINIC_PARENT_VISIBLE_NOTES', false),
        'parent_visible_assessment' => (bool) env('CLINIC_PARENT_VISIBLE_ASSESSMENT', false),
        'parent_visible_diagnosis' => (bool) env('CLINIC_PARENT_VISIBLE_DIAGNOSIS', true),
        'parent_visible_medication' => (bool) env('CLINIC_PARENT_VISIBLE_MEDICATION', true),
        'notify_on_visit' => (bool) env('CLINIC_NOTIFY_ON_VISIT', true),
        // Peristiwa yang selalu diberitakan, tidak dapat dimatikan dari UI.
        'mandatory_events' => ['EMERGENCY', 'SENT_HOME', 'REFERRAL'],
    ],

    // Retensi data kesehatan.
    'retention' => [
        'archive_after_years' => (int) env('CLINIC_ARCHIVE_AFTER_YEARS', 5),
        'restrict_after_years' => (int) env('CLINIC_RESTRICT_AFTER_YEARS', 10),
        'allow_hard_delete' => (bool) env('CLINIC_ALLOW_HARD_DELETE', false),
    ],

    // Disk privat untuk dokumen kesehatan.
    'document_disk' => env('CLINIC_DOCUMENT_DISK', 'clinic'),
];
