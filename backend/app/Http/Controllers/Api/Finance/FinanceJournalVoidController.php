<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\FinancialTransaction;
use App\Models\FinanceJournalVoidRequest;
use App\Services\Finance\Accounting\JournalVoidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Permohonan pembatalan jurnal.
 *
 * Jurnal yang sudah diposting tidak dihapus dan tidak disunting: koreksinya
 * adalah jurnal pembalik. Endpoint di sini mengatur izin & jejaknya, bukan
 * mekanisme pembalikannya (itu milik engine).
 */
class FinanceJournalVoidController extends BaseApiController
{
    public function __construct(private readonly JournalVoidService $voids) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->voids->paginate((int) $request->input('per_page', 25)));
    }

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transaction_id' => ['required', 'integer', 'exists:finance_transactions,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $transaction = FinancialTransaction::query()->findOrFail($data['transaction_id']);

        return $this->createdResponse(
            $this->voids->request($transaction, (string) $data['reason'], $request->user()),
            'Permohonan pembatalan diajukan.',
        );
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $permohonan = FinanceJournalVoidRequest::query()->findOrFail($id);

        return $this->successResponse(
            $this->voids->decide($permohonan, (bool) $data['approved'], $request->user(), $data['note'] ?? null),
            $data['approved'] ? 'Jurnal dibatalkan melalui jurnal pembalik.' : 'Permohonan pembatalan ditolak.',
        );
    }
}
