<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FinanceInvoiceResource;
use App\Models\FinanceInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invoice (§16) — baca-saja. Penerbitan lewat `FinanceBillingController@invoice`;
 * pembatalan lewat pembatalan billing (yang membalik jurnal akrual). `paid_amount`
 * dan `status` bergerak hanya lewat payment terverifikasi (§21), bukan di sini.
 */
class FinanceInvoiceController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = FinanceInvoice::query()
            ->with(['student', 'billing', 'costCenter'])
            ->withCount('items')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', (int) $request->input('student_id')))
            ->when($request->filled('billing_id'), fn (Builder $q) => $q->where('billing_id', (int) $request->input('billing_id')))
            ->when($request->filled('overdue'), fn (Builder $q) => $q
                ->whereIn('status', [FinanceInvoice::STATUS_ISSUED, FinanceInvoice::STATUS_PARTIAL, FinanceInvoice::STATUS_OVERDUE])
                ->whereDate('due_date', '<', now()->toDateString())
                ->where('remaining_amount', '>', 0))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where('invoice_number', 'like', "%$term%");
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceInvoiceResource::collection($query->get()), 'Daftar invoice berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceInvoiceResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinanceInvoice::STATUSES, 'status_labels' => FinanceInvoice::STATUS_LABELS]]),
            'Daftar invoice berhasil diambil',
        );
    }

    public function show(FinanceInvoice $invoice): JsonResponse
    {
        $invoice->load(['student', 'billing', 'costCenter', 'items.account', 'items.feeType']);

        return $this->successResponse(new FinanceInvoiceResource($invoice), 'Detail invoice berhasil diambil');
    }
}
