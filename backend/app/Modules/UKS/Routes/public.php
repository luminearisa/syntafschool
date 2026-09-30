<?php

use App\Modules\UKS\Http\Controllers\UksPublicController;
use Illuminate\Support\Facades\Route;

/*
| Satu-satunya endpoint UKS tanpa autentikasi: pemeriksaan keaslian surat
| keterangan. Dibatasi ketat per IP karena menjadi sasaran tebakan kode.
*/
Route::prefix('uks')->group(function (): void {
    Route::get('letters/verify/{code}', [UksPublicController::class, 'verify'])
        ->where('code', '[A-Za-z0-9]{4,12}')
        ->middleware('throttle:uks-public-verify')
        ->name('uks.letters.verify');
});
