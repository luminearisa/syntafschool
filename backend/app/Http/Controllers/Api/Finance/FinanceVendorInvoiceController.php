<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceVendorInvoiceRequest;
use App\Http\Resources\FinanceVendorInvoiceResource;
use App\Models\FinanceVendorInvoice;
use App\Services\Finance\FinanceVendorInvoiceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Invoice vendor + Accounts Payable (§35, §36). Terima invoice → akui hutang;
 * bayar (sebagian/seluruh) → Dr Hutang, Cr Kas/Bank. `outstanding` menyaring
 * invoice yang masih menghutang untuk laporan AP.
 */
class FinanceVendorInvoiceController extends BaseApiController
{
    public function __construct(private readonly FinanceVendorInvoiceService $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceVendorInvoice::query()
            ->with(['vendor', 'purchaseOrder', 'costCenter', 'account'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('vendor_id'), fn (Builder $q) => $q->where('vendor_id', (int) $request->input('vendor_id')))
            ->when($request->filled('purchase_order_id'), fn (Builder $q) => $q->where('purchase_order_id', (int) $request->input('purchase_order_id')))
            ->when($request->boolean('outstanding'), fn (Builder $q) => $q->outstanding())
            ->when($request->filled('due_before'), fn (Builder $q) => $q->whereDate('due_date', '<=', $request->input('due_before')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where('vendor_invoice_number', 'like', "%$term%");
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceVendorInvoiceResource::collection($query->get()), 'Daftar invoice vendor berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceVendorInvoiceResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinanceVendorInvoice::STATUSES, 'status_labels' => FinanceVendorInvoice::STATUS_LABELS]]),
            'Daftar invoice vendor berhasil diambil',
        );
    }

    public function show(FinanceVendorInvoice $vendorInvoice): JsonResponse
    {
        $vendorInvoice->load(['vendor', 'purchaseOrder', 'costCenter', 'account']);

        return $this->successResponse(new FinanceVendorInvoiceResource($vendorInvoice), 'Detail invoice vendor berhasil diambil');
    }

    public function store(FinanceVendorInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoices->receive($request->validated(), $request->user());

        return $this->createdResponse(
            new FinanceVendorInvoiceResource($invoice->load(['vendor', 'account'])),
            'Invoice vendor berhasil diterima',
        );
    }

    /** Tandai invoice draft jadi diterima dan akui hutangnya. */
    public function receive(Request $request, FinanceVendorInvoice $vendorInvoice): JsonResponse
    {
        $invoice = $this->invoices->markReceived($vendorInvoice, $request->user());

        return $this->successResponse(new FinanceVendorInvoiceResource($invoice->load('vendor')), 'Invoice vendor ditandai diterima');
    }

    /** Bayar invoice vendor (§36) — boleh sebagian. */
    public function pay(Request $request, FinanceVendorInvoice $vendorInvoice): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_account_id' => ['nullable', 'integer', Rule::exists('finance_cash_accounts', 'id')->whereNull('deleted_at')],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('finance_bank_accounts', 'id')->whereNull('deleted_at')],
            'paid_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = $this->invoices->pay($vendorInvoice, $data, $request->user());

        return $this->successResponse(new FinanceVendorInvoiceResource($invoice->load('vendor')), 'Pembayaran invoice vendor dicatat');
    }

    public function cancel(Request $request, FinanceVendorInvoice $vendorInvoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $invoice = $this->invoices->cancel($vendorInvoice, (string) $data['reason'], $request->user());

        return $this->successResponse(new FinanceVendorInvoiceResource($invoice->load('vendor')), 'Invoice vendor dibatalkan');
    }
}
