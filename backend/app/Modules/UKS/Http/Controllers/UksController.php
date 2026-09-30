<?php

namespace App\Modules\UKS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/** Dasar controller modul UKS: amplop respons standar + batas halaman. */
abstract class UksController extends Controller
{
    use ApiResponse;

    /** Halaman dibatasi 1–100 agar satu permintaan tidak dapat meminta seluruh tabel. */
    protected function perPage(Request $request, int $default = 15): int
    {
        return min(100, max(1, (int) $request->integer('per_page', $default)));
    }
}
