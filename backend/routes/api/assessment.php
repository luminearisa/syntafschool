<?php

use App\Http\Controllers\Api\AcademicExportController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AssessmentTypeController;
use App\Http\Controllers\Api\AssessmentWeightController;
use App\Http\Controllers\Api\GradebookController;
use App\Http\Controllers\Api\GradeScaleController;
use App\Http\Controllers\Api\GradingFormulaController;
use App\Http\Controllers\Api\PassingGradeRuleController;
use App\Http\Controllers\Api\RemedialController;
use App\Http\Controllers\Api\ScoreController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| A7 — Penilaian (Assessment) & Grading Engine
|--------------------------------------------------------------------------
|
| File ini dimuat otomatis oleh routes/api.php di dalam grup `v1` +
| `auth:sanctum`, jadi tidak perlu prefix maupun middleware auth di sini.
|
| Pembagian permission:
| - assessment.*     → konfigurasi penilaian & CRUD penilaian
| - academic.grade   → menulis, menghapus, dan menyerahkan nilai (guru); setiap
|                      penghapusan tercatat di score_revisions sehingga tetap auditabel
| - academic.approve → verifikasi, finalisasi (kunci), dan membuka kembali
|
*/

// ------------------------------------------------- Konfigurasi jenis & bobot
Route::get('assessment-types', [AssessmentTypeController::class, 'index'])
    ->middleware('permission:assessment.view')->name('assessment-types.index');
Route::post('assessment-types', [AssessmentTypeController::class, 'store'])
    ->middleware('permission:assessment.create')->name('assessment-types.store');
Route::get('assessment-types/{type}', [AssessmentTypeController::class, 'show'])
    ->middleware('permission:assessment.view')->name('assessment-types.show');
Route::match(['put', 'patch'], 'assessment-types/{type}', [AssessmentTypeController::class, 'update'])
    ->middleware('permission:assessment.update')->name('assessment-types.update');
Route::delete('assessment-types/{type}', [AssessmentTypeController::class, 'destroy'])
    ->middleware('permission:assessment.delete')->name('assessment-types.destroy');

// --------------------------------------------------------------- Skala nilai
Route::get('grade-scales', [GradeScaleController::class, 'index'])
    ->middleware('permission:assessment.view')->name('grade-scales.index');
Route::post('grade-scales', [GradeScaleController::class, 'store'])
    ->middleware('permission:assessment.create')->name('grade-scales.store');
Route::get('grade-scales/{scale}', [GradeScaleController::class, 'show'])
    ->middleware('permission:assessment.view')->name('grade-scales.show');
Route::match(['put', 'patch'], 'grade-scales/{scale}', [GradeScaleController::class, 'update'])
    ->middleware('permission:assessment.update')->name('grade-scales.update');
Route::delete('grade-scales/{scale}', [GradeScaleController::class, 'destroy'])
    ->middleware('permission:assessment.delete')->name('grade-scales.destroy');

// ------------------------------------------------------------- KKM / KKTP
Route::get('passing-grades', [PassingGradeRuleController::class, 'index'])
    ->middleware('permission:assessment.view')->name('passing-grades.index');
Route::get('passing-grades/resolve', [PassingGradeRuleController::class, 'resolve'])
    ->middleware('permission:assessment.view')->name('passing-grades.resolve');
Route::post('passing-grades', [PassingGradeRuleController::class, 'store'])
    ->middleware('permission:assessment.create')->name('passing-grades.store');
Route::get('passing-grades/{rule}', [PassingGradeRuleController::class, 'show'])
    ->middleware('permission:assessment.view')->name('passing-grades.show');
Route::match(['put', 'patch'], 'passing-grades/{rule}', [PassingGradeRuleController::class, 'update'])
    ->middleware('permission:assessment.update')->name('passing-grades.update');
Route::delete('passing-grades/{rule}', [PassingGradeRuleController::class, 'destroy'])
    ->middleware('permission:assessment.delete')->name('passing-grades.destroy');

// ---------------------------------------------------------------- Bobot
Route::get('assessment-weights', [AssessmentWeightController::class, 'index'])
    ->middleware('permission:assessment.view')->name('assessment-weights.index');
Route::post('assessment-weights', [AssessmentWeightController::class, 'store'])
    ->middleware('permission:assessment.update')->name('assessment-weights.store');
Route::match(['put', 'patch'], 'assessment-weights/{weight}', [AssessmentWeightController::class, 'update'])
    ->middleware('permission:assessment.update')->name('assessment-weights.update');
Route::delete('assessment-weights/{weight}', [AssessmentWeightController::class, 'destroy'])
    ->middleware('permission:assessment.delete')->name('assessment-weights.destroy');

// ------------------------------------------------------------- Formula nilai
Route::get('grading-formulas', [GradingFormulaController::class, 'index'])
    ->middleware('permission:assessment.view')->name('grading-formulas.index');
Route::post('grading-formulas', [GradingFormulaController::class, 'store'])
    ->middleware('permission:assessment.create')->name('grading-formulas.store');
Route::get('grading-formulas/{formula}', [GradingFormulaController::class, 'show'])
    ->middleware('permission:assessment.view')->name('grading-formulas.show');
Route::match(['put', 'patch'], 'grading-formulas/{formula}', [GradingFormulaController::class, 'update'])
    ->middleware('permission:assessment.update')->name('grading-formulas.update');
Route::delete('grading-formulas/{formula}', [GradingFormulaController::class, 'destroy'])
    ->middleware('permission:assessment.delete')->name('grading-formulas.destroy');

// Lookups form pembuatan penilaian (khusus pengguna yang boleh membuat penilaian).
Route::get('assessment-lookups', [AssessmentController::class, 'lookups'])
    ->middleware('permission:assessment.view')->name('assessment-lookups');

// ---------------------------------------------------------------- Penilaian
Route::get('assessments', [AssessmentController::class, 'index'])
    ->middleware('permission:assessment.view')->name('assessments.index');
Route::post('assessments', [AssessmentController::class, 'store'])
    ->middleware('permission:assessment.create')->name('assessments.store');
Route::get('assessments/{assessment}', [AssessmentController::class, 'show'])
    ->middleware('permission:assessment.view')->name('assessments.show');
Route::match(['put', 'patch'], 'assessments/{assessment}', [AssessmentController::class, 'update'])
    ->middleware('permission:assessment.update')->name('assessments.update');
Route::delete('assessments/{assessment}', [AssessmentController::class, 'destroy'])
    ->middleware('permission:assessment.delete')->name('assessments.destroy');

// Lembar nilai & statistik
Route::get('assessments/{assessment}/sheet', [AssessmentController::class, 'sheet'])
    ->middleware('permission:assessment.view')->name('assessments.sheet');
Route::get('assessments/{assessment}/stats', [AssessmentController::class, 'stats'])
    ->middleware('permission:assessment.view')->name('assessments.stats');

// Alur persetujuan nilai
Route::post('assessments/{assessment}/submit', [AssessmentController::class, 'submit'])
    ->middleware('permission:academic.grade')->name('assessments.submit');
Route::post('assessments/{assessment}/verify', [AssessmentController::class, 'verify'])
    ->middleware('permission:academic.approve')->name('assessments.verify');
Route::post('assessments/{assessment}/finalize', [AssessmentController::class, 'finalize'])
    ->middleware('permission:academic.approve')->name('assessments.finalize');
Route::post('assessments/{assessment}/reopen', [AssessmentController::class, 'reopen'])
    ->middleware('permission:academic.approve')->name('assessments.reopen');

// ------------------------------------------------------------------ Nilai
Route::get('assessments/{assessment}/scores', [ScoreController::class, 'index'])
    ->middleware('permission:assessment.view')->name('assessments.scores.index');
Route::post('assessments/{assessment}/scores', [ScoreController::class, 'store'])
    ->middleware('permission:academic.grade')->name('assessments.scores.store');
Route::post('assessments/{assessment}/scores/bulk', [ScoreController::class, 'bulkStore'])
    ->middleware('permission:academic.grade')->name('assessments.scores.bulk');
Route::match(['put', 'patch'], 'assessments/{assessment}/scores/{score}', [ScoreController::class, 'update'])
    ->middleware('permission:academic.grade')->name('assessments.scores.update');
Route::delete('assessments/{assessment}/scores/{score}', [ScoreController::class, 'destroy'])
    ->middleware('permission:academic.grade')->name('assessments.scores.destroy');

// -------------------------------------------------------------- Remedial
Route::get('assessments/{assessment}/remedials', [RemedialController::class, 'index'])
    ->middleware('permission:assessment.view')->name('assessments.remedials.index');
Route::get('assessments/{assessment}/remedials/candidates', [RemedialController::class, 'candidates'])
    ->middleware('permission:assessment.view')->name('assessments.remedials.candidates');
Route::post('assessments/{assessment}/remedials', [RemedialController::class, 'store'])
    ->middleware('permission:academic.grade')->name('assessments.remedials.store');
Route::match(['put', 'patch'], 'assessments/{assessment}/remedials/{remedial}', [RemedialController::class, 'update'])
    ->middleware('permission:academic.grade')->name('assessments.remedials.update');
Route::delete('assessments/{assessment}/remedials/{remedial}', [RemedialController::class, 'destroy'])
    ->middleware('permission:academic.grade')->name('assessments.remedials.destroy');

// ------------------------------------------------------------ Buku nilai
Route::get('gradebook', [GradebookController::class, 'index'])
    ->middleware('permission:assessment.view')->name('gradebook.index');
Route::get('gradebook/overview', [GradebookController::class, 'overview'])
    ->middleware('permission:assessment.view')->name('gradebook.overview');
Route::get('gradebook/export', [AcademicExportController::class, 'gradebook'])
    ->middleware('permission:assessment.view')->name('gradebook.export');

// Nilai milik satu siswa (portal siswa & wali murid memakai endpoint ini).
// `assessment.view` untuk staf; `academic.portal` untuk akun keluarga (siswa/
// wali) yang tidak lagi memegang permission modul. Pagar kepemilikan
// (`student.family`) tetap menahan mereka hanya pada dirinya/anaknya.
Route::get('students/{student}/scores', [ScoreController::class, 'studentScores'])
    ->middleware(['permission:assessment.view|academic.portal', 'student.family'])->name('students.scores.index');
