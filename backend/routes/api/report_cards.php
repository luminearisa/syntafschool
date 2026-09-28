<?php

use App\Http\Controllers\Api\ReportCardController;
use Illuminate\Support\Facades\Route;

Route::prefix('report-cards')->name('report-cards.')->group(function (): void {
    Route::get('lookups', [ReportCardController::class, 'lookups'])
        ->middleware('permission:report-card.view')->name('lookups');
    Route::get('/', [ReportCardController::class, 'index'])
        ->middleware('permission:report-card.view')->name('index');
    Route::post('generate', [ReportCardController::class, 'generate'])
        ->middleware('permission:report-card.generate')->name('generate');
    Route::get('{reportCard}', [ReportCardController::class, 'show'])
        ->middleware('permission:report-card.view')->name('show');
    Route::get('{reportCard}/pdf', [ReportCardController::class, 'pdf'])
        ->middleware('permission:report-card.view')->name('pdf');
    Route::match(['put', 'patch'], '{reportCard}', [ReportCardController::class, 'update'])
        ->middleware('permission:report-card.update')->name('update');
    Route::post('{reportCard}/ready', [ReportCardController::class, 'ready'])
        ->middleware('permission:report-card.update')->name('ready');
    Route::post('{reportCard}/approve', [ReportCardController::class, 'approve'])
        ->middleware('permission:report-card.approve')->name('approve');
    Route::post('{reportCard}/publish', [ReportCardController::class, 'publish'])
        ->middleware('permission:report-card.publish')->name('publish');
});
