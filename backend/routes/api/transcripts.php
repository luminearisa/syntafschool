<?php

use App\Http\Controllers\Api\TranscriptController;
use Illuminate\Support\Facades\Route;

Route::prefix('transcripts')->name('transcripts.')->group(function (): void {
    Route::get('lookups', [TranscriptController::class, 'lookups'])
        ->middleware('permission:report-card.view')->name('lookups');
    Route::get('/', [TranscriptController::class, 'index'])
        ->middleware('permission:report-card.view')->name('index');
    Route::post('generate', [TranscriptController::class, 'generate'])
        ->middleware('permission:report-card.generate')->name('generate');
    Route::get('{transcript}', [TranscriptController::class, 'show'])
        ->middleware('permission:report-card.view')->name('show');
    Route::get('{transcript}/pdf', [TranscriptController::class, 'pdf'])
        ->middleware('permission:report-card.view')->name('pdf');
});
