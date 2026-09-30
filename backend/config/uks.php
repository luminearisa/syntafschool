<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Batas laju Modul UKS
    |--------------------------------------------------------------------------
    |
    | Nilai = permintaan per menit, dibedakan per pengguna (atau per IP untuk
    | endpoint publik) agar satu jaringan sekolah tidak saling menghabiskan
    | kuota. Dapat diubah tanpa deploy.
    |
    */

    'rate_limits' => [
        // Jaring pengaman seluruh modul.
        'module' => 300,
        // Verifikasi surat keterangan: endpoint publik, sasaran tebakan kode.
        'public_verify' => 30,
        // Pencarian siswa/obat.
        'search' => 90,
        // Mencatat kunjungan & memberi obat.
        'visit_write' => 120,
        // Ekspor laporan.
        'report' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ambang pengingat
    |--------------------------------------------------------------------------
    */

    // Obat yang kedaluwarsa dalam N hari ke depan ikut diperingatkan.
    'expiry_warning_days' => 30,

    // Imunisasi yang jatuh tempo dalam N hari ke depan ikut diperingatkan.
    'immunization_due_days' => 14,
];
