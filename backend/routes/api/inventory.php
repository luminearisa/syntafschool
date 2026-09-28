<?php

use App\Http\Controllers\Api\InventoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory')->name('inventory.')->group(function (): void {
    Route::get('lookups', [InventoryController::class, 'lookups'])
        ->middleware('permission:inventory.view')->name('lookups');
    Route::get('items', [InventoryController::class, 'index'])
        ->middleware('permission:inventory.view')->name('items.index');
    Route::post('items', [InventoryController::class, 'store'])
        ->middleware('permission:inventory.create')->name('items.store');
    Route::get('items/{item}', [InventoryController::class, 'show'])
        ->middleware('permission:inventory.view')->name('items.show');
    Route::match(['put', 'patch'], 'items/{item}', [InventoryController::class, 'update'])
        ->middleware('permission:inventory.update')->name('items.update');
    Route::post('items/{item}/adjust', [InventoryController::class, 'adjust'])
        ->middleware('permission:inventory.update')->name('items.adjust');
    Route::delete('items/{item}', [InventoryController::class, 'destroy'])
        ->middleware('permission:inventory.delete')->name('items.destroy');
});
