<?php

/*
|--------------------------------------------------------------------------
| Modul HRD / Kepegawaian
|--------------------------------------------------------------------------
|
| Semua aturan yang "seharusnya dapat diubah sekolah" tinggal di sini, bukan di
| dalam kode: pola nomor pegawai, jendela pengingat, kebijakan retensi dokumen,
| ambang aturan validasi, dan pemetaan status ke master lama.
|
| Nilai bawaan dibuat untuk yayasan dengan beberapa unit (sekolah + pesantren),
| tetapi tidak ada satu pun yang wajib: sekolah satu unit dapat mengabaikan
| seluruh bagian organisasi berjenjang tanpa mengubah kode.
|
*/

return [

    /*
    | Jembatan ke tabel `employees` lama.
    |
    | Modul akademik, presensi, jurnal, dan aset lama masih menunjuk
    | `employees.id`. HRD tetap menjadi tempat data diubah, lalu menuliskan
    | proyeksi ringkasnya ke tabel lama supaya modul-modul itu tetap bekerja.
    | Matikan pada instalasi baru yang belum pernah memakai tabel lama.
    */
    'legacy_bridge' => [
        'enabled' => (bool) env('HRD_LEGACY_BRIDGE', true),

        // `employees.employment_status` lama bertipe enum: PNS | PPPK | Honorer | GTY.
        // Pemetaan ini yang menentukan nilai proyeksi; tambahkan kunci sesuai
        // worker/employment type yang dipakai sekolah.
        'employment_status_map' => [
            'Permanent' => 'PNS',
            'Fixed Term' => 'PPPK',
            'Contract' => 'PPPK',
            'Probation' => 'Honorer',
            'Part Time' => 'Honorer',
            'Internship' => 'Honorer',
            'Temporary' => 'Honorer',
            'Daily Worker' => 'Honorer',
            'Project Based' => 'Honorer',
        ],
        'default_employment_status' => 'Honorer',
    ],

    /*
    | Pola nomor dokumen HR (§14). `{TAHUN}` diisi tahun, `{URUT}` nomor urut
    | berpadding, `{UNIT}` kode unit organisasi, `{PREFIX}` awalan baris sekuens.
    | Nomor pegawai TIDAK boleh berubah setelah terbit — pola ini hanya dipakai
    | saat menerbitkan nomor baru.
    */
    'numbering' => [
        'employee' => env('HRD_EMPLOYEE_PATTERN', 'EMP-{URUT}'),
        'transaction' => env('HRD_TRANSACTION_PATTERN', 'TRX-{TAHUN}-{URUT}'),
        'request' => env('HRD_REQUEST_PATTERN', 'REQ-{TAHUN}-{URUT}'),
        'contract' => env('HRD_CONTRACT_PATTERN', 'CTR-{TAHUN}-{URUT}'),
        'onboarding' => env('HRD_ONBOARDING_PATTERN', 'ONB-{TAHUN}-{URUT}'),
        'offboarding' => env('HRD_OFFBOARDING_PATTERN', 'OFB-{TAHUN}-{URUT}'),
        'merge' => env('HRD_MERGE_PATTERN', 'MRG-{TAHUN}-{URUT}'),
        'padding' => 4,
    ],

    /*
    | Jendela pengingat (hari sebelum tanggal berakhir). Dipakai penjadwal harian
    | untuk kontrak, dokumen, sertifikasi, percobaan, dan tenggat tugas onboarding.
    */
    'reminders' => [
        'contract_days' => [90, 60, 30, 14, 7],
        'document_days' => [60, 30, 14],
        'certification_days' => [60, 30, 14],
        'probation_days' => [30, 14, 7],
        'probation_review_days_before_end' => 14,
        'onboarding_due_days' => [7, 3, 1],
        'offboarding_due_days' => [7, 3, 1],
        'request_sla_warning_hours' => 24,
    ],

    // Berapa hari sebelum tanggal berakhir sebuah dokumen/sertifikat dianggap
    // "akan berakhir" (status dihitung ulang oleh penjadwal, bukan diketik).
    'expiry_warning_days' => env('HRD_EXPIRY_WARNING_DAYS', 60),

    // Batas unggah dokumen pegawai (KB) dan jenis berkas yang diterima.
    'documents' => [
        'max_size_kb' => (int) env('HRD_DOCUMENT_MAX_KB', 10240),
        'allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'],
        'disk' => env('HRD_DOCUMENT_DISK', 'hrd'),
        'signed_url_ttl_minutes' => 15,
    ],

    /*
    | Aturan validasi bawaan. Seeder menuliskannya ke `hr_validation_rules`
    | sehingga sekolah dapat menonaktifkan atau mengubah ambangnya tanpa deploy.
    | `handler` menunjuk pemeriksa yang terdaftar di ValidationEngine.
    */
    'validation' => [
        'enforce_on_save' => true,
        'blocking_severity' => ['critical'],
    ],

    // Ambang skor kualitas data pada dasbor (§56).
    'data_quality' => [
        'weights' => ['critical' => 5, 'warning' => 2, 'info' => 1],
        'minimum_score' => 80,
    ],

    /*
    | Cakupan data (row-level scope). Menentukan apa yang boleh dibaca:
    | pegawai → dirinya, manajer → timnya, HR → unitnya, HR admin → seluruhnya.
    */
    'scopes' => [
        'enable_manager_scope' => true,
        'manager_scope_depth' => 3,
        'enable_organization_scope' => true,
    ],

    // Kode definisi workflow bawaan untuk tiap kategori transaksi. Bila definisi
    // belum ada di Workflow Engine, transaksi tetap tercatat dan menunggu
    // persetujuan manual — tidak ada yang hilang.
    'workflows' => [
        'transaction' => env('HRD_WORKFLOW_TRANSACTION', 'HRD_TRANSACTION_APPROVAL'),
        'request' => env('HRD_WORKFLOW_REQUEST', 'HRD_REQUEST_APPROVAL'),
        'offboarding' => env('HRD_WORKFLOW_OFFBOARDING', 'HRD_OFFBOARDING_APPROVAL'),
        'merge' => env('HRD_WORKFLOW_MERGE', 'HRD_EMPLOYEE_MERGE'),
    ],

    // Berapa kali transaksi boleh dikirim ulang ke Workflow Engine sebelum
    // dicatat sebagai gagal (tetap dapat diproses manual dari antrean).
    'workflow_retry' => [
        'max_attempts' => 3,
    ],

    /*
    | Integrasi keluar. Setiap peristiwa yang perlu diketahui modul lain ditulis
    | lebih dulu ke `hr_integration_outbox`, lalu dikirim oleh penjadwal. Modul
    | tujuan yang belum terpasang tidak membuat peristiwa hilang.
    */
    'integration' => [
        'dispatch_chunk' => 100,
        'max_attempts' => 5,
        'notify_roles' => ['HR_ADMIN', 'HR_MANAGER'],
    ],

    // Kunci sandi kolom sensitif (NIK, nomor rekening) bila enkripsi diaktifkan.
    // Dibiarkan kosong = kolom disimpan apa adanya tetapi disamarkan saat dibaca
    // pemanggil tanpa izin `hr.employee.sensitive.view`.
    'encryption' => [
        'identifiers' => (bool) env('HRD_ENCRYPT_IDENTIFIERS', false),
        'masking' => [
            'nik' => 'partial',
            'bank_account' => 'full',
            'npwp' => 'partial',
        ],
    ],

    // Peran yang otomatis mendapat akses global HR (bukan teknis).
    'roles' => [
        'global' => ['HR_ADMIN', 'HR_MANAGER'],
        'auditor' => ['HR_AUDITOR', 'AUDITOR'],
    ],
];
