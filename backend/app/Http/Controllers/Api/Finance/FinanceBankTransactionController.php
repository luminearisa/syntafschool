<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceBankTransactionRequest;
use App\Http\Resources\FinanceBankTransactionResource;
use App\Models\FinanceBankTransaction;
use App\Services\Finance\FinanceBankTransactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transaksi bank (§41): deposit/withdrawal/fee standalone. Pencatatan memposting
 * jurnal double-entry yang menggerakkan saldo rekening (logika di service).
 */
class FinanceBankTransactionController extends BaseApiController
{
    public function __construct(private readonly FinanceBankTransactionService $bankTransactions) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceBankTransaction::query()
            ->with(['bankAccount', 'transfer'])
            ->when($request->filled('bank_account_id'), fn (Builder $q) => $q->where('bank_account_id', (int) $request->input('bank_account_id')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('transaction_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('transaction_date', '<=', $request->input('date_to')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('reference', 'like', "%$term%")
                    ->orWhere('description', 'like', "%$term%"));
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceBankTransactionResource::collection($query->get()), 'Daftar transaksi bank berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceBankTransactionResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['types' => FinanceBankTransaction::TYPES]]),
            'Daftar transaksi bank berhasil diambil',
        );
    }

    public function show(FinanceBankTransaction $bankTransaction): JsonResponse
    {
        $bankTransaction->load(['bankAccount', 'transfer', 'createdBy']);

        return $this->successResponse(new FinanceBankTransactionResource($bankTransaction), 'Detail transaksi bank berhasil diambil');
    }

    public function store(FinanceBankTransactionRequest $request): JsonResponse
    {
        $transaction = $this->bankTransactions->record($request->validated(), $request->user());

        return $this->createdResponse(
            new FinanceBankTransactionResource($transaction->load('bankAccount')),
            'Transaksi bank berhasil dicatat',
        );
    }
}
