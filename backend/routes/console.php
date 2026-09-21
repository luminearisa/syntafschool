<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Penjadwalan modul Library (§10).
 *
 * Satu job harian idempoten: tandai keterlambatan, nilai denda, kedaluwarsakan
 * reservasi & keanggotaan, lalu kirim pengingat. Dijalankan setelah jam
 * operasional agar data hari itu sudah final.
 */
Schedule::command('library:daily-maintenance')->dailyAt('20:00');

/*
 * Penjadwalan modul PPDB (siklus tagihan).
 *
 * Job harian idempoten: tagihan pendaftaran/daftar ulang yang lewat jatuh tempo
 * dan belum dibayar dipindahkan ke `expired` sehingga piutang di portal, daftar
 * admin, dan dashboard tidak menampilkan tunggakan yang masih "hidup" selamanya.
 * Dijalankan setelah tengah hari agar pembayaran yang masih dicatat hari itu
 * tidak tertendang. Penolakan transisinya (mis. sudah ada pembayaran sah)
 * dilaporkan perintah ini, tidak diubah diam-diam.
 */
Schedule::command('ppdb:expire-invoices')->dailyAt('12:30');