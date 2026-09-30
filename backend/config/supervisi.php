<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Batas laju Modul Supervisi
    |--------------------------------------------------------------------------
    |
    | Nilai = jumlah permintaan per menit. Dapat diubah sekolah tanpa deploy
    | (mis. sekolah besar yang mengekspor banyak laporan sekaligus). Limiter
    | dibedakan per pengguna bila pengguna dikenal, agar satu jaringan sekolah
    | (NAT) tidak saling menghabiskan kuota.
    |
    */

    'rate_limits' => [
        // Batas menyeluruh seluruh modul: jaring pengaman agar satu klien yang
        // berputar-putar (loop, skrip) tidak dapat membanjiri layanan.
        'module' => 300,

        // Berkas dan kode verifikasi: endpoint publik, sasaran percobaan
        // menebak kode dokumen.
        'public_verify' => 30,

        // Membangun laporan memanggil banyak agregat dan menulis berkas.
        'report_generate' => 20,

        // Dasbor analitik: satu permintaan = belasan kueri agregat.
        'analytics' => 60,

        // Menyimpan jawaban instrumen (mode daring & sinkronisasi luring).
        'answer_save' => 240,

        // Unggahan bukti (foto/dokumen) — berat di penyimpanan.
        'evidence_upload' => 30,

        // Finalisasi menyegel nilai: langka dan berdampak besar.
        'visit_finalize' => 20,

        // Pencarian teks (kotak pencarian daftar).
        'search' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retensi berkas laporan
    |--------------------------------------------------------------------------
    |
    | Berkas dokumen (CSV/XLSX/PDF) dapat dihapus setelah sekian bulan untuk
    | menjaga penyimpanan; **baris riwayatnya tetap ada** (kode verifikasi,
    | sidik digital, siapa & kapan membuat) sehingga jejak dokumen tidak hilang.
    | Penghapusan hanya berjalan dengan `--force` — lihat `supervisi:prune`.
    |
    */

    'report_retention_months' => 24,

];
