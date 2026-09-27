<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FinanceExpenseResource;
use App\Models\FinanceExpense;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daftar expense terealisasi (§30) — baca-saja. Pencatatan & pembayaran lewat
 * alur pengajuan di {@see FinanceExpenseRequestController}.
 */
class FinanceExpenseController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = FinanceExpense::query()
            ->with(['expenseRequest', 'costCenter', 'account', 'vendor', 'createdBy'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('cost_center_id'), fn (Builder $q) => $q->where('cost_center_id', (int) $request->input('cost_center_id')))
            ->when($request->filled('account_id'), fn (Builder $q) => $q->where('account_id', (int) $request->input('account_id')))
            ->when($request->filled('vendor_id'), fn (Builder $q) => $q->where('vendor_id', (int) $request->input('vendor_id')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('expense_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('expense_date', '<=', $request->input('date_to')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('expense_number', 'like', "%$term%")
                    ->orWhere('description', 'like', "%$term%"));
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceExpenseResource::collection($query->get()), 'Daftar pengeluaran berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceExpenseResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar pengeluaran berhasil diambil',
        );
    }

    public function show(FinanceExpense $expense): JsonResponse
    {
        $expense->load(['expenseRequest', 'costCenter', 'account', 'vendor', 'createdBy']);

        return $this->successResponse(new FinanceExpenseResource($expense), 'Detail pengeluaran berhasil diambil');
    }
}
