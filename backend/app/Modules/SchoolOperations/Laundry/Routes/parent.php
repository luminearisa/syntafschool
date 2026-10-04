<?php

use App\Modules\SchoolOperations\Laundry\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Laundry — Parent API (§63): /api/v1/parent/...
|--------------------------------------------------------------------------
| Wali hanya dapat melihat anak yang menjadi authorized dependent
| (Rule 20) — diverifikasi per request di PortalController.
*/

Route::get('laundry/children', [PortalController::class, 'children']);

Route::prefix('children/{studentId}/laundry')->group(function (): void {
    Route::get('/', [PortalController::class, 'childOverview']);
    Route::get('quota', [PortalController::class, 'childQuota']);
    Route::get('orders', [PortalController::class, 'childOrders']);
    Route::get('orders/{orderId}', [PortalController::class, 'childOrder']);
    Route::get('statement', [PortalController::class, 'childStatement']);
});
