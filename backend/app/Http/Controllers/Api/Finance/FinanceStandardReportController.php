<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Finance\Accounting\StandardReportService;
use App\Support\Accounting\SumberModul;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Laporan akuntansi standar: neraca saldo, laba rugi per unit, laporan dana.
 *
 * Angka dihitung dengan BCMath dan jurnal yang dibatalkan tetap dihitung bersama
 * jurnal pembaliknya — supaya total laporan selalu sama dengan buku besar.
 */
class FinanceStandardReportController extends BaseApiController
{
    public function __construct(private readonly StandardReportService $reports) {}

    public function trialBalance(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->trialBalance(
            $request->query('from'),
            $request->query('to'),
        ));
    }

    public function profitLossByUnit(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->profitLossByUnit(
            $request->query('from'),
            $request->query('to'),
        ));
    }

    public function fundStatement(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->fundStatement(
            $request->query('from'),
            $request->query('to'),
        ));
    }

    /** Daftar modul asal jurnal yang sah — dipakai layar pemetaan unit. */
    public function modules(): JsonResponse
    {
        return $this->successResponse(SumberModul::options());
    }
}
