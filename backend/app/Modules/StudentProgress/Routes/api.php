<?php

use App\Modules\StudentProgress\Http\Controllers\StudentAchievementController;
use App\Modules\StudentProgress\Http\Controllers\StudentViolationController;
use Illuminate\Support\Facades\Route;

/*
 * Rute modul StudentProgress (Prestasi & Pelanggaran siswa).
 * Prefix `api/v1` sudah ditambahkan ServiceProvider modul.
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('student-achievements')->name('student-achievements.')->group(function () {
        Route::get('lookups', [StudentAchievementController::class, 'lookups'])->middleware('permission:progress.achievement.view');
        Route::get('/', [StudentAchievementController::class, 'index'])->middleware('permission:progress.achievement.view');
        Route::post('/', [StudentAchievementController::class, 'store'])->middleware('permission:progress.achievement.create');
        Route::get('{achievement}', [StudentAchievementController::class, 'show'])->middleware('permission:progress.achievement.view');
        Route::match(['put', 'patch'], '{achievement}', [StudentAchievementController::class, 'update'])->middleware('permission:progress.achievement.update');
        Route::delete('{achievement}', [StudentAchievementController::class, 'destroy'])->middleware('permission:progress.achievement.delete');
    });

    Route::prefix('student-violations')->name('student-violations.')->group(function () {
        Route::get('lookups', [StudentViolationController::class, 'lookups'])->middleware('permission:progress.violation.view');
        Route::get('summary', [StudentViolationController::class, 'summary'])->middleware('permission:progress.point.view');
        Route::get('/', [StudentViolationController::class, 'index'])->middleware('permission:progress.violation.view');
        Route::post('/', [StudentViolationController::class, 'store'])->middleware('permission:progress.violation.create');
        Route::get('{violation}', [StudentViolationController::class, 'show'])->middleware('permission:progress.violation.view');
        Route::match(['put', 'patch'], '{violation}', [StudentViolationController::class, 'update'])->middleware('permission:progress.violation.update');
        Route::post('{violation}/verify', [StudentViolationController::class, 'verify'])->middleware('permission:progress.violation.verify');
        Route::post('{violation}/resolve', [StudentViolationController::class, 'resolve'])->middleware('permission:progress.violation.verify');
        Route::delete('{violation}', [StudentViolationController::class, 'destroy'])->middleware('permission:progress.violation.delete');
    });
});
