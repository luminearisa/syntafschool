<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceReconciliationItemRequest;
use App\Http\Requests\Finance\FinanceReconciliationRequest;
use App\Http\Resources\FinanceReconciliationItemResource;
use App\Http\Resources\FinanceReconciliationResource;
use App\Models\FinanceReconciliation;
use App\Models\FinanceReconciliationItem;
use App\Services\Finance\FinanceReconciliationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rekonsiliasi bank (§44, §45): buka → tambah/cocokkan baris koran → finalize
 * (reconciled bila selisih nol, selain itu discrepancy → perlu adjustment §51).
 */
class FinanceReconciliationController extends BaseApiController
{
    public function __construct(private readonly FinanceReconciliationService $reconciliations) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceReconciliation::query()
            ->with(['bankAccount', 'bankStatement:id,statement_number'])
            ->when($request->filled('bank_account_id'), fn (Builder $q) => $q->where('bank_account_id', (int) $request->input('bank_account_id')))
            ->when($request->filled('bank_statement_id'), fn (Builder $q) => $q->where('bank_statement_id', (int) $request->input('bank_statement_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('statement_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('statement_date', '<=', $request->input('date_to')))
            ->orderByDesc('statement_date')
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceReconciliationResource::collection($query->get()), 'Daftar rekonsiliasi berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceReconciliationResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinanceReconciliation::STATUSES, 'status_labels' => FinanceReconciliation::STATUS_LABELS]]),
            'Daftar rekonsiliasi berhasil diambil',
        );
    }

    public function show(FinanceReconciliation $reconciliation): JsonResponse
    {
        $reconciliation->load(['bankAccount', 'bankStatement:id,statement_number', 'items', 'reconciledBy']);

        return $this->successResponse(new FinanceReconciliationResource($reconciliation), 'Detail rekonsiliasi berhasil diambil');
    }

    public function store(FinanceReconciliationRequest $request): JsonResponse
    {
        $reconciliation = $this->reconciliations->open($request->validated(), $request->user());

        return $this->createdResponse(
            new FinanceReconciliationResource($reconciliation->load('bankAccount')),
            'Rekonsiliasi bank dibuka',
        );
    }

    public function addItem(FinanceReconciliationItemRequest $request, FinanceReconciliation $reconciliation): JsonResponse
    {
        $item = $this->reconciliations->addItem($reconciliation, $request->validated(), $request->user());

        return $this->createdResponse(new FinanceReconciliationItemResource($item), 'Baris rekonsiliasi ditambahkan');
    }

    public function updateItem(Request $request, FinanceReconciliation $reconciliation, FinanceReconciliationItem $reconciliationItem): JsonResponse
    {
        if ((int) $reconciliationItem->reconciliation_id !== (int) $reconciliation->id) {
            throw ValidationException::withMessages(['reconciliation_item' => 'Baris tidak termasuk rekonsiliasi ini.']);
        }

        $data = $request->validate([
            'match_status' => ['required', Rule::in(FinanceReconciliationItem::MATCH_STATUSES)],
            'bank_transaction_id' => ['nullable', 'integer', Rule::exists('finance_bank_transactions', 'id')],
        ]);

        $item = $this->reconciliations->setItemStatus(
            $reconciliationItem,
            (string) $data['match_status'],
            isset($data['bank_transaction_id']) ? (int) $data['bank_transaction_id'] : null,
        );

        return $this->successResponse(new FinanceReconciliationItemResource($item), 'Status pencocokan baris diperbarui');
    }

    public function finalize(Request $request, FinanceReconciliation $reconciliation): JsonResponse
    {
        $updated = $this->reconciliations->finalize($reconciliation, $request->user());

        return $this->successResponse(
            new FinanceReconciliationResource($updated->load(['bankAccount', 'bankStatement:id,statement_number', 'items'])),
            'Rekonsiliasi difinalkan',
        );
    }
}
