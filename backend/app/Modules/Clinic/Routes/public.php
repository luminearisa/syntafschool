<?php

use App\Modules\Clinic\Http\Controllers\ClinicCertificateController;
use Illuminate\Support\Facades\Route;

/*
| Route publik Modul Clinic.
|
| Hanya satu: verifikasi keaslian surat keterangan kesehatan lewat kode acak.
| Endpoint ini sengaja tidak menampilkan identitas pasien maupun isi klinis —
| pihak luar hanya perlu tahu surat itu sah dan masih berlaku.
*/

Route::prefix('clinic/public')->middleware('throttle:clinic-module')->group(function (): void {
    Route::get('certificates/{code}/verify', [ClinicCertificateController::class, 'verify'])
        ->name('clinic.public.certificate.verify');
});
