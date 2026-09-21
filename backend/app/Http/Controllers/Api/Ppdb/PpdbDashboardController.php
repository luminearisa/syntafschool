<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbReportFilterRequest;
use App\Services\Ppdb\PpdbDashboardService;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard admin PPDB.
 *
 * Rute: `GET /api/v1/ppdb/dashboard` (`permission:ppdb.view`).
 *
 * Filter opsional sama persis dengan laporan (`PpdbReportFilterRequest`):
 * `period_id` (kosong = periode non-arsip terbaru, `all` = semua periode),
 * `wave_id`, `program_id`, `date_from`, `date_to`, `date_basis`, `status`,
 * `payment_status`, `document_status`, `keyword`, `granularity`.
 */
class PpdbDashboardController extends BaseApiController
{
    public function __construct(protected readonly PpdbDashboardService $dashboard)
    {
    }

    /** Statistik, corong, keuangan, distribusi, aksi tertunda, dan tren. */
    public function admin(PpdbReportFilterRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboard->admin($request->filters()),
            'Dashboard PPDB berhasil diambil'
        );
    }
}
