<?php

use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AssignmentSubmissionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| A11b — Tugas (Assignment) & Pengumpulan
|--------------------------------------------------------------------------
|
| File ini dimuat otomatis oleh routes/api.php di dalam grup `v1` +
| `auth:sanctum`, jadi tidak perlu prefix maupun middleware auth di sini.
| Otorisasi tetap per-route lewat `permission:academic.*`.
|
*/

// ---------------------------------------------------------------- Tugas
Route::get('assignments', [AssignmentController::class, 'index'])
    ->middleware('permission:academic.view')->name('assignments.index');

Route::get('assignments/recap', [AssignmentController::class, 'recap'])
    ->middleware('permission:academic.view')->name('assignments.recap');

Route::post('assignments', [AssignmentController::class, 'store'])
    ->middleware('permission:academic.create|academic.grade')->name('assignments.store');

Route::get('assignments/{assignment}', [AssignmentController::class, 'show'])
    ->middleware('permission:academic.view')->name('assignments.show');

Route::match(['put', 'patch'], 'assignments/{assignment}', [AssignmentController::class, 'update'])
    ->middleware('permission:academic.update|academic.grade')->name('assignments.update');

Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy'])
    ->middleware('permission:academic.delete')->name('assignments.destroy');

Route::post('assignments/{assignment}/publish', [AssignmentController::class, 'publish'])
    ->middleware('permission:academic.publish|academic.grade')->name('assignments.publish');

Route::post('assignments/{assignment}/close', [AssignmentController::class, 'close'])
    ->middleware('permission:academic.update|academic.grade')->name('assignments.close');

// ---------------------------------------------------- Pengumpulan tugas
Route::get('assignments/{assignment}/submissions', [AssignmentController::class, 'submissions'])
    ->middleware('permission:academic.view')->name('assignments.submissions.index');

Route::post('assignments/{assignment}/submissions', [AssignmentSubmissionController::class, 'store'])
    ->middleware('permission:academic.create|academic.grade')->name('assignments.submissions.store');

Route::match(['put', 'patch'], 'assignments/{assignment}/submissions/{submission}', [AssignmentSubmissionController::class, 'update'])
    ->middleware('permission:academic.update|academic.grade')->name('assignments.submissions.update');

Route::post('assignments/{assignment}/submissions/{submission}/grade', [AssignmentSubmissionController::class, 'grade'])
    ->middleware('permission:academic.grade')->name('assignments.submissions.grade');

Route::delete('assignments/{assignment}/submissions/{submission}', [AssignmentSubmissionController::class, 'destroy'])
    ->middleware('permission:academic.delete|academic.grade')->name('assignments.submissions.destroy');

// -------------------------------------------- Tugas milik satu siswa
Route::get('students/{student}/assignments', [AssignmentSubmissionController::class, 'studentAssignments'])
    ->middleware(['permission:academic.view|academic.portal', 'student.family'])->name('students.assignments.index');
