<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base controller untuk seluruh endpoint API.
 *
 * `AuthorizesRequests` ditambahkan agar controller dapat memanggil
 * `$this->authorize()` (Policy) di samping middleware `permission:` yang
 * sudah dipakai seluruh modul.
 */
abstract class BaseApiController extends Controller
{
    use ApiResponse, AuthorizesRequests;
}
