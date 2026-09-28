<?php

use App\Http\Controllers\Api\GeneralSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->name('settings.')->group(function (): void {
    Route::get('general', [GeneralSettingsController::class, 'show'])
        ->middleware('permission:setting.view')->name('general.show');
    Route::match(['put', 'patch'], 'general', [GeneralSettingsController::class, 'update'])
        ->middleware('permission:setting.update')->name('general.update');
});
