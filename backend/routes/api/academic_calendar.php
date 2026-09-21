<?php

use App\Http\Controllers\Api\AcademicCalendarController;
use App\Http\Controllers\Api\EventTypeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| A4 — Kalender Akademik & Hari Efektif
|--------------------------------------------------------------------------
|
| File ini dimuat otomatis oleh routes/api.php di dalam grup `v1` +
| `auth:sanctum`, jadi tidak perlu prefix maupun middleware auth di sini.
| Otorisasi tetap per-route lewat `permission:academic.*`.
|
*/

// ------------------------------------------------------------- Jenis event
Route::get('event-types', [EventTypeController::class, 'index'])
    ->middleware('permission:academic.view')->name('event-types.index');

Route::post('event-types', [EventTypeController::class, 'store'])
    ->middleware('permission:academic.create')->name('event-types.store');

Route::get('event-types/{eventType}', [EventTypeController::class, 'show'])
    ->middleware('permission:academic.view')->name('event-types.show');

Route::match(['put', 'patch'], 'event-types/{eventType}', [EventTypeController::class, 'update'])
    ->middleware('permission:academic.update')->name('event-types.update');

Route::delete('event-types/{eventType}', [EventTypeController::class, 'destroy'])
    ->middleware('permission:academic.delete')->name('event-types.destroy');

// --------------------------------------------------------- Event akademik
Route::get('academic-events', [AcademicCalendarController::class, 'indexEvents'])
    ->middleware('permission:academic.view')->name('academic-events.index');

Route::post('academic-events', [AcademicCalendarController::class, 'storeEvent'])
    ->middleware('permission:academic.create')->name('academic-events.store');

// Kalender per bulan (harus sebelum `{academicEvent}`).
Route::get('academic-calendar', [AcademicCalendarController::class, 'calendar'])
    ->middleware('permission:academic.view')->name('academic-calendar.index');

Route::get('academic-events/{academicEvent}', [AcademicCalendarController::class, 'showEvent'])
    ->middleware('permission:academic.view')->name('academic-events.show');

Route::match(['put', 'patch'], 'academic-events/{academicEvent}', [AcademicCalendarController::class, 'updateEvent'])
    ->middleware('permission:academic.update')->name('academic-events.update');

Route::delete('academic-events/{academicEvent}', [AcademicCalendarController::class, 'destroyEvent'])
    ->middleware('permission:academic.delete')->name('academic-events.destroy');

// -------------------------------------------------------------- Hari efektif
Route::get('effective-days', [AcademicCalendarController::class, 'indexEffectiveDays'])
    ->middleware('permission:academic.view')->name('effective-days.index');

// Route statis didahulukan agar tidak tertangkap `{effectiveDay}`.
Route::get('effective-days/summary', [AcademicCalendarController::class, 'summary'])
    ->middleware('permission:academic.view')->name('effective-days.summary');

Route::post('effective-days/generate', [AcademicCalendarController::class, 'generateEffectiveDays'])
    ->middleware('permission:academic.create')->name('effective-days.generate');

Route::post('effective-days', [AcademicCalendarController::class, 'storeEffectiveDay'])
    ->middleware('permission:academic.create')->name('effective-days.store');

Route::get('effective-days/{effectiveDay}', [AcademicCalendarController::class, 'showEffectiveDay'])
    ->middleware('permission:academic.view')->name('effective-days.show');

Route::match(['put', 'patch'], 'effective-days/{effectiveDay}', [AcademicCalendarController::class, 'updateEffectiveDay'])
    ->middleware('permission:academic.update')->name('effective-days.update');

Route::delete('effective-days/{effectiveDay}', [AcademicCalendarController::class, 'destroyEffectiveDay'])
    ->middleware('permission:academic.delete')->name('effective-days.destroy');
