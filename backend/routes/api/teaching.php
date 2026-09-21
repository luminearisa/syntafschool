<?php

use App\Http\Controllers\Api\LessonPlanController;
use App\Http\Controllers\Api\TeachingJournalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| A11a — Jurnal Mengajar & RPP / Modul Ajar
|--------------------------------------------------------------------------
|
| File ini dimuat otomatis oleh routes/api.php di dalam grup `v1` +
| `auth:sanctum`, jadi tidak perlu prefix maupun middleware auth di sini.
| Otorisasi tetap per-route lewat `permission:academic.*`.
|
| Catatan permission: guru memegang `academic.attendance`, sehingga aksi tulis
| yang memang milik guru (jurnal & RPP miliknya) mengizinkan permission itu
| berdampingan dengan `academic.create`/`academic.update`. Persetujuan RPP dan
| pembukaan kunci jurnal selesai tetap khusus `academic.approve`.
|
*/

// ------------------------------------------------------ RPP / Modul ajar
Route::get('lesson-plans', [LessonPlanController::class, 'index'])
    ->middleware('permission:academic.view')->name('lesson-plans.index');

Route::post('lesson-plans', [LessonPlanController::class, 'store'])
    ->middleware('permission:academic.create|academic.attendance')->name('lesson-plans.store');

Route::get('lesson-plans/{plan}', [LessonPlanController::class, 'show'])
    ->middleware('permission:academic.view')->name('lesson-plans.show');

Route::match(['put', 'patch'], 'lesson-plans/{plan}', [LessonPlanController::class, 'update'])
    ->middleware('permission:academic.update|academic.attendance')->name('lesson-plans.update');

Route::delete('lesson-plans/{plan}', [LessonPlanController::class, 'destroy'])
    ->middleware('permission:academic.delete|academic.attendance')->name('lesson-plans.destroy');

Route::post('lesson-plans/{plan}/submit', [LessonPlanController::class, 'submit'])
    ->middleware('permission:academic.update|academic.attendance')->name('lesson-plans.submit');

Route::post('lesson-plans/{plan}/approve', [LessonPlanController::class, 'approve'])
    ->middleware('permission:academic.approve')->name('lesson-plans.approve');

Route::post('lesson-plans/{plan}/reject', [LessonPlanController::class, 'reject'])
    ->middleware('permission:academic.approve')->name('lesson-plans.reject');

// ---------------------------------------------------------- Jurnal mengajar
Route::get('teaching-journals', [TeachingJournalController::class, 'index'])
    ->middleware('permission:academic.view')->name('teaching-journals.index');

// Route statis didahulukan agar tidak tertangkap `{journal}`.
Route::get('teaching-journals/today', [TeachingJournalController::class, 'today'])
    ->middleware('permission:academic.view')->name('teaching-journals.today');

Route::get('teaching-journals/recap', [TeachingJournalController::class, 'recap'])
    ->middleware('permission:academic.view')->name('teaching-journals.recap');

Route::post('teaching-journals', [TeachingJournalController::class, 'store'])
    ->middleware('permission:academic.create|academic.attendance')->name('teaching-journals.store');

Route::get('teaching-journals/{journal}', [TeachingJournalController::class, 'show'])
    ->middleware('permission:academic.view')->name('teaching-journals.show');

Route::match(['put', 'patch'], 'teaching-journals/{journal}', [TeachingJournalController::class, 'update'])
    ->middleware('permission:academic.update|academic.attendance|academic.approve')->name('teaching-journals.update');

Route::delete('teaching-journals/{journal}', [TeachingJournalController::class, 'destroy'])
    ->middleware('permission:academic.delete|academic.attendance')->name('teaching-journals.destroy');

Route::post('teaching-journals/{journal}/complete', [TeachingJournalController::class, 'complete'])
    ->middleware('permission:academic.update|academic.attendance|academic.approve')->name('teaching-journals.complete');
