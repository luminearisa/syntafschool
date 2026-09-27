<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceExpenseRequestRequest;
use App\Http\Resources\FinanceExpenseRequestResource;
use App\Http\Resources\FinanceExpenseResource;
use App\Models\FinanceExpenseRequest;
use App\Services\Finance\FinanceExpenseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pengajuan pengeluaran (§30–§33): buat → ajukan → approve bertingkat / reject →
 * bayar. Logika di `FinanceExpenseService`; controller tipis. Pembayaran
 * (`pay`) memposting jurnal keluar dan membuat expense terealisasi.
 */
class FinanceExpenseRequestController extends BaseApiController
{
    public function __construct(private readonly FinanceExpenseService $expenses) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceExpenseRequest::query()
            ->with(['requester', 'costCenter', 'account', 'vendor', 'approvedBy'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('cost_center_id'), fn (Builder $q) => $q->where('cost_center_id', (int) $request->input('cost_center_id')))
            ->when($request->filled('account_id'), fn (Builder $q) => $q->where('account_id', (int) $request->input('account_id')))
            ->when($request->boolean('mine'), fn (Builder $q) => $q->where('requester_id', $request->user()?->id))
            ->when($request->boolean('pending'), fn (Builder $q) => $q->pendingApproval())
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('expense_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('expense_date', '<=', $request->input('date_to')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('request_number', 'like', "%$term%")
                    ->orWhere('description', 'like', "%$term%"));
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceExpenseRequestResource::collection($query->get()), 'Daftar pengajuan pengeluaran berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceExpenseRequestResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinanceExpenseRequest::STATUSES, 'status_labels' => FinanceExpenseRequest::STATUS_LABELS]]),
            'Daftar pengajuan pengeluaran berhasil diambil',
        );
    }

    public function show(FinanceExpenseRequest $expenseRequest): JsonResponse
    {
        $expenseRequest->load(['requester', 'costCenter', 'account', 'vendor', 'approvedBy', 'expenses']);

        return $this->successResponse(new FinanceExpenseRequestResource($expenseRequest), 'Detail pengajuan pengeluaran berhasil diambil');
    }

    public function store(FinanceExpenseRequestRequest $request): JsonResponse
    {
        $expenseRequest = $this->expenses->createRequest($request->validated(), $request->user());

        return $this->createdResponse(
            new FinanceExpenseRequestResource($expenseRequest->load(['costCenter', 'account'])),
            'Pengajuan pengeluaran berhasil dibuat',
        );
    }

    public function submit(Request $request, FinanceExpenseRequest $expenseRequest): JsonResponse
    {
        $updated = $this->expenses->submit($expenseRequest, $request->user());

        return $this->successResponse(new FinanceExpenseRequestResource($updated), 'Pengajuan pengeluaran diajukan');
    }

    public function approve(Request $request, FinanceExpenseRequest $expenseRequest): JsonResponse
    {
        $updated = $this->expenses->approve($expenseRequest, $request->user());

        return $this->successResponse(new FinanceExpenseRequestResource($updated), 'Pengajuan pengeluaran disetujui');
    }

    public function reject(Request $request, FinanceExpenseRequest $expenseRequest): JsonResponse
    {
        $data = $request->validate(['reject_reason' => ['required', 'string', 'max:500']]);

        $updated = $this->expenses->reject($expenseRequest, (string) $data['reject_reason'], $request->user());

        return $this->successResponse(new FinanceExpenseRequestResource($updated), 'Pengajuan pengeluaran ditolak');
    }

    public function cancel(FinanceExpenseRequest $expenseRequest): JsonResponse
    {
        $updated = $this->expenses->cancel($expenseRequest);

        return $this->successResponse(new FinanceExpenseRequestResource($updated), 'Pengajuan pengeluaran dibatalkan');
    }

    /** Bayar pengajuan yang sudah disetujui: posting jurnal + expense terealisasi (§30). */
    public function pay(Request $request, FinanceExpenseRequest $expenseRequest): JsonResponse
    {
        $data = $request->validate([
            'cash_account_id' => ['nullable', 'integer', Rule::exists('finance_cash_accounts', 'id')->whereNull('deleted_at')],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('finance_bank_accounts', 'id')->whereNull('deleted_at')],
            'expense_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $expense = $this->expenses->pay($expenseRequest, $data, $request->user());

        return $this->createdResponse(
            new FinanceExpenseResource($expense->load(['costCenter', 'account', 'expenseRequest'])),
            'Pengeluaran berhasil dibayar',
        );
    }
}
