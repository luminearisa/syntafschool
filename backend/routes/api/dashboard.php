<?php

use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

// Each analytics section checks its own read permission in the controller.
Route::get('dashboard/overview', [DashboardController::class, 'overview'])
    ->name('dashboard.overview');
