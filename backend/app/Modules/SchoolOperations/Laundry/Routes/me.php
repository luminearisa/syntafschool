<?php

use App\Modules\SchoolOperations\Laundry\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Laundry — Student API (§62): /api/v1/me/laundry
|--------------------------------------------------------------------------
| Identitas santri dibaca dari akun (ContextService), tidak pernah dari
| parameter request — santri tidak bisa melihat data orang lain.
*/

Route::get('quota', [PortalController::class, 'meQuota']);
Route::get('orders', [PortalController::class, 'meOrders']);
Route::get('orders/{id}', [PortalController::class, 'meOrder']);
Route::get('statement', [PortalController::class, 'meStatement']);
