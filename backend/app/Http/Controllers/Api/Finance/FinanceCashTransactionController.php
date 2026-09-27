<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceCashTransactionRequest;
use App\Http\Resources\FinanceCashTransactionResource;
use App\Models\FinanceCashTransaction;
use App\Services\Finance\FinanceCashTransactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transaksi kas (§2): kas masuk/keluar standalone.
 *
 * Hanya ada index/show/store — tidak ada update maupun delete. Baris ini adalah
 * dokumen sumber sebuah jurnal; mengubahnya berarti membuat buku besar dan
 * dokumen tidak lagi bercerita hal yang sama. Koreksi dilakukan dengan
 * mencatat transaksi lawan (atau reversal jurnalnya), sesuai §51.
 */
class FinanceCashTransactionController extends BaseApiController
{
    public function __construct(private readonly FinanceCashTransactionService $cashTransactions) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceCashTransaction::query()
            ->with(['cashAccount:id,code,name', 'contraAccount:id,code,name', 'financeTransaction:id,transaction_number'])
            ->when($request->filled('cash_account_id'), fn (Builder $q) => $q->where('cash_account_id', (int) $request->input('cash_account_id')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('transaction_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('transaction_date', '<=', $request->input('date_to')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('transaction_number', 'like', "%$term%")
                    ->orWhere('reference', 'like', "%$term%")
                    ->orWhere('description', 'like', "%$term%"));
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceCashTransactionResource::collection($query->get()), 'Daftar transaksi kas berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceCashTransactionResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['types' => FinanceCashTransaction::TYPES, 'type_labels' => FinanceCashTransaction::TYPE_LABELS]]),
            'Daftar transaksi kas berhasil diambil',
        );
    }

    public function show(FinanceCashTransaction $cashTransaction): JsonResponse
    {
        $cashTransaction->load([
            'cashAccount:id,code,name',
            'contraAccount:id,code,name',
            'financeTransaction:id,transaction_number,type,status',
            'createdBy:id,name',
        ]);

        return $this->successResponse(new FinanceCashTransactionResource($cashTransaction), 'Detail transaksi kas berhasil diambil');
    }

    public function store(FinanceCashTransactionRequest $request): JsonResponse
    {
        $transaction = $this->cashTransactions->record($request->validated(), $request->user());

        return $this->createdResponse(
            new FinanceCashTransactionResource($transaction->load([
                'cashAccount:id,code,name',
                'contraAccount:id,code,name',
                'financeTransaction:id,transaction_number,type,status',
            ])),
            'Transaksi kas berhasil dicatat dan diposting',
        );
    }
}
