<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FinanceVendorInvoiceResource;
use App\Services\Finance\FinancePayableService;
use App\Services\Finance\FinanceReceivableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hutang / AP (§24–§31): daftar invoice vendor outstanding, aging per vendor,
 * dan total kewajiban. Cermin `FinanceReceivableController`; baris datar untuk
 * tabel/CSV disediakan `FinanceReportService` (kind `payables`) supaya layar
 * dan ekspor berbagi satu sumber angka.
 */
class FinancePayableController extends BaseApiController
{
    public function __construct(private readonly FinancePayableService $payables) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->payables->query($request->query())->orderBy('due_date')->orderBy('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                FinanceVendorInvoiceResource::collection($query->get()),
                'Daftar hutang berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceVendorInvoiceResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => [
                    'buckets' => FinanceReceivableService::BUCKETS,
                    'bucket_labels' => FinanceReceivableService::BUCKET_LABELS,
                ]]),
            'Daftar hutang berhasil diambil',
        );
    }

    /** Ringkasan aging AP: total per ember umur + matriks per vendor. */
    public function aging(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->payables->aging($request->query()),
            'Aging hutang berhasil diambil',
        );
    }

    /** Total hutang + yang jatuh tempo. */
    public function summary(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->payables->summary($request->query()),
            'Ringkasan hutang berhasil diambil',
        );
    }
}
