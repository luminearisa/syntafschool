<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Finance\FinanceDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard keuangan (§67): satu panggilan mengembalikan seluruh KPI beranda
 * modul — posisi kas/bank, piutang, performa bulan berjalan, realisasi anggaran,
 * antrean persetujuan, dan jurnal terbaru.
 */
class FinanceDashboardController extends BaseApiController
{
    public function __construct(private readonly FinanceDashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboard->overview($request->user()),
            'Dashboard keuangan berhasil diambil',
        );
    }
}
