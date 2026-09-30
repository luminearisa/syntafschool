<?php

use App\Models\FinanceOperation;
use App\Services\Finance\FinanceOperationService;
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

/*
 * Penjadwalan modul Keuangan (§15 Phase 2, §7+§28 Phase 5).
 *
 * Job harian idempoten: tagihan/invoice yang lewat jatuh tempo dan masih punya
 * sisa dipindah ke `overdue`, tangga pengingat H-7/H-3/H-1/H+1/H+7 (bilangan
 * milik Settings, bukan kode) dikirim ke santri + wali, daftar ulang yang
 * melewati batas dipindah ke `expired`. Perintah yang sama boleh dijalankan
 * berulang: dokumen yang sama tidak dinotifikasi dua kali.
 *
 * Tugas yang berat dilempar ke antrian lewat register operasi, bukan dijalankan
 * langsung sebagai perintah, supaya run malam itu sendiri punya baris ber-status
 * (Queued → Processing → Completed/Failed) dan hasil akhirnya terbaca bendahara
 * tanpa membuka log server (§28). Perintah aslinya tetap ada: itu jalur sinkron
 * bagi host tanpa worker, dan yang dikerjakan job di balik antrian ini.
 */
Schedule::call(fn () => app(FinanceOperationService::class)->dispatch(FinanceOperation::JOB_DAILY_MAINTENANCE))
    ->dailyAt('21:00')
    ->name('finance:daily-maintenance (antrian)');

/*
 * Batas waktu pembayaran gateway/VA (§7).
 *
 * Di luar job harian karena satuan waktunya jam: kanal yang sudah mati harus
 * lepas dari status "sedang dibayar" pada sapuan berikutnya, bukan besok malam,
 * supaya wali bisa mengulang pembayaran dan tagihannya naik ke tangga penagihan.
 * Tetap perintah langsung: 48 sapuan sehari tidak layak menjadi 48 baris di
 * register, dan yang berat di sini hanyalah queries-nya.
 */
Schedule::command('finance:expire-payments')->everyThirtyMinutes();

/*
 * Generate SPP terjadwal (§7, §28).
 *
 * Menjalankan service yang sama dengan tombol "generate" di layar
 * (`FinanceBillingService::generateScheduled()`), untuk jendela periode yang
 * sedang berjalan (bulanan, triwulan, semester, atau tahun ajaran) — penjaga
 * "fitur belum aktif" tinggal di service, jadi jalur antrian tidak bisa
 * menagih diam-diam. Fitur mati sampai bendahara mengaktifkannya di Settings →
 * Keuangan; bila aktif, pekerjaan ini aman dijalankan berulang karena tagihan
 * kedua untuk (santri, paket, periode) yang sama tidak pernah lahir.
 */
Schedule::call(fn () => app(FinanceOperationService::class)->dispatch(FinanceOperation::JOB_SPP_GENERATE))
    ->dailyAt('06:00')
    ->name('finance:generate-spp (antrian)');

/*
 * Penjadwalan modul LMS (§11).
 *
 * Seluruh pekerjaan berat dijalankan di antrean — penjadwal hanya memicu, tidak
 * menghitung. Setiap perintah idempotent sehingga aman bila penjadwal berjalan
 * dua kali (mis. setelah pemulihan restart).
 */
Schedule::command('lms:automation')->dailyAt('21:00')->withoutOverlapping();
Schedule::command('lms:reminders')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('lms:reminders --overdue')->dailyAt('07:15')->withoutOverlapping();
Schedule::command('lms:grade-sync --retry-failed')->everyTenMinutes()->withoutOverlapping();
Schedule::command('lms:prune')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('lms:integrity')->weeklyOn(1, '03:00')->withoutOverlapping();

/*
 * Penjadwalan modul Supervisi (Fase 4).
 *
 * Pengingat harian: kegiatan yang dijadwalkan H-3, kegiatan selesai yang belum
 * difinalkan, butir RTL jatuh tempo/terlambat, tindak lanjut coaching, dan
 * tinjauan PIP yang mendekati tenggat. Perintahnya idempoten — satu pengingat
 * per (penerima, jenis, kunci) per hari — sehingga aman dijalankan berulang.
 */
Schedule::command('supervisi:remind')->dailyAt('06:30')->withoutOverlapping();
// Pemeriksaan integritas mingguan (Senin pagi) — hanya melapor, tidak mengubah.
Schedule::command('supervisi:health')->weeklyOn(1, '07:00')->withoutOverlapping();
// Penyapu berkas dokumen: **tanpa** --force oleh penjadwal, jadi hanya melapor.
// Penghapusan sengaja menuntut tindakan manual admin.
Schedule::command('supervisi:prune')->monthlyOn(1, '07:15')->withoutOverlapping();
