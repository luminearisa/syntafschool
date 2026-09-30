<?php

use App\Modules\UKS\Http\Controllers\UksIncidentController;
use App\Modules\UKS\Http\Controllers\UksLetterController;
use App\Modules\UKS\Http\Controllers\UksMedicineController;
use App\Modules\UKS\Http\Controllers\UksMetaController;
use App\Modules\UKS\Http\Controllers\UksOfficerController;
use App\Modules\UKS\Http\Controllers\UksRecordController;
use App\Modules\UKS\Http\Controllers\UksReportController;
use App\Modules\UKS\Http\Controllers\UksVisitController;
use Illuminate\Support\Facades\Route;

/*
| Route Modul UKS — seluruhnya di bawah `/api/v1/uks/*`.
|
| Middleware `permission:uks.*` menentukan layar yang boleh dibuka; baris mana
| yang terlihat ditegakkan di `UksAccessService` (petugas/admin: semua, guru:
| kelas yang diampu, orang tua: anaknya, siswa: dirinya sendiri).
*/

Route::middleware(['auth:sanctum', 'throttle:uks-module'])->prefix('uks')->group(function (): void {

    // ---------------- Konteks & metadata ----------------
    Route::get('meta', [UksMetaController::class, 'metadata'])
        ->middleware('permission:uks.view')->name('uks.meta');
    Route::get('me', [UksMetaController::class, 'me'])
        ->middleware('permission:uks.view')->name('uks.me');
    Route::get('students', [UksRecordController::class, 'visibleStudents'])
        ->middleware('throttle:uks-search')
        ->middleware('permission:uks.view')->name('uks.students');

    // ---------------- Kunjungan ----------------
    Route::get('visits', [UksVisitController::class, 'index'])
        ->middleware('permission:uks.visit.view')->name('uks.visits.index');
    Route::post('visits', [UksVisitController::class, 'store'])
        ->middleware('throttle:uks-visit-write')
        ->middleware('permission:uks.visit.create')->name('uks.visits.store');
    Route::get('visits/{visit}', [UksVisitController::class, 'show'])
        ->middleware('permission:uks.visit.view')->name('uks.visits.show');
    Route::post('visits/{visit}/handle', [UksVisitController::class, 'handle'])
        ->middleware('throttle:uks-visit-write')
        ->middleware('permission:uks.visit.manage')->name('uks.visits.handle');
    Route::post('visits/{visit}/medications', [UksVisitController::class, 'medication'])
        ->middleware('throttle:uks-visit-write')
        ->middleware('permission:uks.visit.manage')->name('uks.visits.medications');
    Route::post('visits/{visit}/referral', [UksVisitController::class, 'refer'])
        ->middleware('permission:uks.visit.manage')->name('uks.visits.referral');
    Route::post('visits/{visit}/consent', [UksVisitController::class, 'consent'])
        ->middleware('permission:uks.consent.create')->name('uks.visits.consent');
    Route::post('visits/{visit}/notify-parent', [UksVisitController::class, 'notifyParent'])
        ->middleware('permission:uks.visit.manage')->name('uks.visits.notify-parent');
    Route::post('visits/{visit}/close', [UksVisitController::class, 'close'])
        ->middleware('permission:uks.visit.manage')->name('uks.visits.close');

    // ---------------- Obat & stok ----------------
    Route::get('medicines', [UksMedicineController::class, 'index'])
        ->middleware('throttle:uks-search')
        ->middleware('permission:uks.medicine.view')->name('uks.medicines.index');
    Route::post('medicines', [UksMedicineController::class, 'store'])
        ->middleware('permission:uks.medicine.manage')->name('uks.medicines.store');
    Route::put('medicines/{medicine}', [UksMedicineController::class, 'update'])
        ->middleware('permission:uks.medicine.manage')->name('uks.medicines.update');
    Route::post('medicines/{medicine}/movements', [UksMedicineController::class, 'movement'])
        ->middleware('permission:uks.medicine.manage')->name('uks.medicines.movements.store');
    Route::get('medicines/{medicine}/movements', [UksMedicineController::class, 'movements'])
        ->middleware('permission:uks.medicine.view')->name('uks.medicines.movements');

    // ---------------- Rekam kesehatan ----------------
    Route::get('records/{student}', [UksRecordController::class, 'show'])
        ->middleware('permission:uks.record.view')->name('uks.records.show');
    Route::put('records/{student}/profile', [UksRecordController::class, 'saveProfile'])
        ->middleware('permission:uks.record.manage')->name('uks.records.profile');

    Route::get('screenings', [UksRecordController::class, 'screenings'])
        ->middleware('permission:uks.record.view')->name('uks.screenings.index');
    Route::post('screenings', [UksRecordController::class, 'storeScreening'])
        ->middleware('permission:uks.record.manage')->name('uks.screenings.store');

    Route::get('immunizations', [UksRecordController::class, 'immunizations'])
        ->middleware('permission:uks.record.view')->name('uks.immunizations.index');
    Route::post('immunizations', [UksRecordController::class, 'storeImmunization'])
        ->middleware('permission:uks.record.manage')->name('uks.immunizations.store');

    // ---------------- Insiden ----------------
    Route::get('incidents', [UksIncidentController::class, 'index'])
        ->middleware('permission:uks.incident.view')->name('uks.incidents.index');
    Route::post('incidents', [UksIncidentController::class, 'store'])
        ->middleware('permission:uks.incident.create')->name('uks.incidents.store');
    Route::put('incidents/{incident}', [UksIncidentController::class, 'update'])
        ->middleware('permission:uks.incident.manage')->name('uks.incidents.update');

    // ---------------- Surat keterangan ----------------
    Route::get('letters', [UksLetterController::class, 'index'])
        ->middleware('permission:uks.letter.view')->name('uks.letters.index');
    Route::post('letters', [UksLetterController::class, 'store'])
        ->middleware('permission:uks.letter.issue')->name('uks.letters.store');
    Route::get('letters/{letter}/download', [UksLetterController::class, 'download'])
        ->middleware('permission:uks.letter.view')->name('uks.letters.download');

    // ---------------- Petugas ----------------
    Route::get('officers', [UksOfficerController::class, 'index'])
        ->middleware('permission:uks.officer.view')->name('uks.officers.index');
    Route::get('officers/candidates', [UksOfficerController::class, 'candidates'])
        ->middleware('throttle:uks-search')
        ->middleware('permission:uks.officer.manage')->name('uks.officers.candidates');
    Route::post('officers', [UksOfficerController::class, 'store'])
        ->middleware('permission:uks.officer.manage')->name('uks.officers.store');
    Route::put('officers/{officer}', [UksOfficerController::class, 'update'])
        ->middleware('permission:uks.officer.manage')->name('uks.officers.update');

    // ---------------- Laporan & kesehatan data ----------------
    Route::get('dashboard', [UksReportController::class, 'dashboard'])
        ->middleware('permission:uks.report.view|uks.visit.view')->name('uks.dashboard');
    Route::get('reports/daily', [UksReportController::class, 'dailyCsv'])
        ->middleware('throttle:uks-report')
        ->middleware('permission:uks.report.export')->name('uks.reports.daily');
    Route::get('health', [UksReportController::class, 'health'])
        ->middleware('permission:uks.manage')->name('uks.health');
});
