<?php

use App\Modules\Mutation\Http\Controllers\StudentTransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('student-transfers')->name('student-transfers.')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('lookups', [StudentTransferController::class, 'lookups'])->middleware('permission:mutation.view');

        Route::get('/', [StudentTransferController::class, 'index'])->middleware('permission:mutation.view');
        Route::post('/', [StudentTransferController::class, 'store'])->middleware('permission:mutation.create');

        Route::get('{transfer}', [StudentTransferController::class, 'show'])->middleware('permission:mutation.view');
        Route::match(['put', 'patch'], '{transfer}', [StudentTransferController::class, 'update'])->middleware('permission:mutation.update');
        Route::delete('{transfer}', [StudentTransferController::class, 'destroy'])->middleware('permission:mutation.delete');

        Route::post('{transfer}/submit', [StudentTransferController::class, 'submit'])->middleware('permission:mutation.update');
        Route::post('{transfer}/approve', [StudentTransferController::class, 'approve'])->middleware('permission:mutation.approve');
        Route::post('{transfer}/reject', [StudentTransferController::class, 'reject'])->middleware('permission:mutation.approve');
        Route::post('{transfer}/complete', [StudentTransferController::class, 'complete'])->middleware('permission:mutation.complete');
    });
});
