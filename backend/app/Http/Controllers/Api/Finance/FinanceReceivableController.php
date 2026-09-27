<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FinanceInvoiceResource;
use App\Services\Finance\FinanceReceivableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Piutang / AR (§26–§28): daftar invoice outstanding, ringkasan aging per ember
 * umur, dan total piutang. Baris datar untuk tabel/CSV ada di FinanceReportService
 * (kind `receivables`/`aging`) agar layar dan ekspor berbagi satu sumber angka.
 */
class FinanceReceivableController extends BaseApiController
{
    public function __construct(private readonly FinanceReceivableService $receivables) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->receivables->query($request->query())->orderBy('due_date')->orderBy('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                FinanceInvoiceResource::collection($query->get()),
                'Daftar piutang berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceInvoiceResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['buckets' => FinanceReceivableService::BUCKETS, 'bucket_labels' => FinanceReceivableService::BUCKET_LABELS]]),
            'Daftar piutang berhasil diambil',
        );
    }

    /** Ringkasan aging: total per ember umur + matriks per customer (§27). */
    public function aging(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->receivables->aging($request->query()),
            'Aging piutang berhasil diambil',
        );
    }

    /** Total piutang + yang jatuh tempo (§26). */
    public function summary(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->receivables->summary($request->query()),
            'Ringkasan piutang berhasil diambil',
        );
    }
}
