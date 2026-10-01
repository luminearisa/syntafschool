<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Bk\Application\Services\BkReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Laporan agregat BK.
 *
 * Semua laporan bebas dari isi catatan: yang keluar hanya angka dan sebaran.
 * Ekspor mengikuti aturan yang sama, sehingga berkas yang diunduh manajemen
 * tidak pernah memuat hal yang bersifat pribadi.
 */
class BkReportController extends BaseApiController
{
    public function __construct(private readonly BkReportService $reports)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(BkReportService::types(), 'Jenis laporan BK.');
    }

    public function show(Request $request, string $type): JsonResponse
    {
        return $this->successResponse(
            $this->reports->generate($request->user(), $type, $request->query('from'), $request->query('to')),
            'Laporan BK.',
        );
    }
}
