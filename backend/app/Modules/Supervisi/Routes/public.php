<?php

use App\Modules\Supervisi\Http\Controllers\SupReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Route Publik Modul Supervisi
|--------------------------------------------------------------------------
|
| Hanya satu endpoint: verifikasi keaslian dokumen supervisi dari kode yang
| tercetak di berkas PDF. Sengaja tanpa autentikasi supaya salinan cetak dapat
| diperiksa pihak luar (pengawas, dinas, orang tua), tetapi hanya keterangan
| dokumen yang diungkap — tidak ada isi laporan yang bisa dibaca dari sini,
| dan kode dibuat acak 8 karakter sehingga tidak dapat diduga.
|
*/
Route::middleware('api')->group(function (): void {
    Route::get('supervisi/reports/verify/{code}', [SupReportController::class, 'verify'])
        ->name('supervisi.reports.verify.public');
});
