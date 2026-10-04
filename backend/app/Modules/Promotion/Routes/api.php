<?php

use App\Modules\Promotion\Http\Controllers\GraduationController;
use App\Modules\Promotion\Http\Controllers\PromotionController;
use App\Modules\Promotion\Http\Controllers\PromotionRuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute modul Promotion (A10 — Kenaikan Kelas & Kelulusan)
|--------------------------------------------------------------------------
| Seluruh rute berada di dalam grup `auth:sanctum`; otorisasi granular
| memakai middleware `permission:` dari Spatie.
*/

Route::middleware('auth:sanctum')->group(function () {

    // ================= ATURAN KENAIKAN =================
    Route::get('promotion-rules', [PromotionRuleController::class, 'index'])->middleware('permission:promotion.view');
    Route::post('promotion-rules', [PromotionRuleController::class, 'store'])->middleware('permission:promotion.manage');
    Route::get('promotion-rules/{rule}', [PromotionRuleController::class, 'show'])->middleware('permission:promotion.view');
    Route::match(['put', 'patch'], 'promotion-rules/{rule}', [PromotionRuleController::class, 'update'])->middleware('permission:promotion.manage');
    Route::delete('promotion-rules/{rule}', [PromotionRuleController::class, 'destroy'])->middleware('permission:promotion.manage');

    // ================= KEPUTUSAN KENAIKAN =================
    Route::get('promotion-decisions/lookups', [PromotionController::class, 'lookups'])->middleware('permission:promotion.view');
    Route::get('promotion-decisions', [PromotionController::class, 'index'])->middleware('permission:promotion.view');
    Route::post('promotion-decisions/evaluate', [PromotionController::class, 'evaluate'])->middleware('permission:promotion.decide');
    Route::post('promotion-decisions/bulk', [PromotionController::class, 'bulk'])->middleware('permission:promotion.decide');
    Route::get('promotion-decisions/{decision}', [PromotionController::class, 'show'])->middleware('permission:promotion.view');
    Route::patch('promotion-decisions/{decision}', [PromotionController::class, 'update'])->middleware('permission:promotion.decide');
    Route::post('promotion-decisions/{decision}/submit', [PromotionController::class, 'submit'])->middleware('permission:promotion.decide');
    Route::post('promotion-decisions/{decision}/approve', [PromotionController::class, 'approve'])->middleware('permission:promotion.approve');
    Route::post('promotion-decisions/{decision}/finalize', [PromotionController::class, 'finalize'])->middleware('permission:promotion.finalize');
    Route::post('promotion-decisions/{decision}/reopen', [PromotionController::class, 'reopen'])->middleware('permission:promotion.finalize');
    Route::delete('promotion-decisions/{decision}', [PromotionController::class, 'destroy'])->middleware('permission:promotion.finalize');

    // ================= KELULUSAN =================
    Route::get('graduations/lookups', [GraduationController::class, 'lookups'])->middleware('permission:graduation.view');
    Route::get('graduations', [GraduationController::class, 'index'])->middleware('permission:graduation.view');
    Route::post('graduations/evaluate', [GraduationController::class, 'evaluate'])->middleware('permission:graduation.decide');
    Route::post('graduations/bulk', [GraduationController::class, 'bulk'])->middleware('permission:graduation.decide');
    Route::get('graduations/{graduation}', [GraduationController::class, 'show'])->middleware('permission:graduation.view');
    Route::patch('graduations/{graduation}', [GraduationController::class, 'update'])->middleware('permission:graduation.manage');
    Route::post('graduations/{graduation}/approve', [GraduationController::class, 'approve'])->middleware('permission:graduation.manage');
    Route::delete('graduations/{graduation}', [GraduationController::class, 'destroy'])->middleware('permission:graduation.manage');
});
