<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Boarding\BoardingDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard asrama (§43) — satu endpoint dengan seluruh angka keadaan hari ini.
 */
class BoardingDashboardController extends BaseApiController
{
    public function __construct(private readonly BoardingDashboardService $dashboard)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboard->stats($request->user()),
            'Dashboard asrama berhasil diambil',
        );
    }
}
