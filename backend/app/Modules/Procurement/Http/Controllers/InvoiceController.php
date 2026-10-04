<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Procurement\Application\Services\AccountingHandoffService;
use App\Modules\Procurement\Application\Services\InvoiceMatchingService;
use App\Modules\Procurement\Application\Services\InvoiceService;
use App\Modules\Procurement\Models\InvoiceException;
use App\Modules\Procurement\Models\VendorInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Invoice vendor, 3-way matching, pengecualian, dan handoff Accounting (§28–§34). */
class InvoiceController extends BaseApiController
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceMatchingService $matching,
        private readonly AccountingHandoffService $handoff,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->invoices->paginate(
            $request->only(['vendor_id', 'purchase_order_id', 'status', 'match_status', 'search']),
            (int) $request->query('per_page', 20),
        );

        return $this->successResponse($page);
    }

    /** Registrasi invoice (§28). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:proc_vendors,id'],
            'vendor_invoice_number' => ['required', 'string', 'max:80'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:proc_purchase_orders,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'source' => ['nullable', 'in:MANUAL,PORTAL,API,UPLOAD,OCR'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer', 'exists:proc_purchase_order_items,id'],
            'items.*.goods_receipt_item_id' => ['nullable', 'integer', 'exists:proc_goods_receipt_items,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['submitted_by'] = $request->user()?->id;

        return $this->createdResponse($this->invoices->intake($data), 'Invoice dicatat');
    }

    public function show(VendorInvoice $vendorInvoice): JsonResponse
    {
        return $this->successResponse($vendorInvoice->load([
            'vendor:id,code,legal_name', 'purchaseOrder:id,number', 'items.purchaseOrderItem:id,description,unit_price',
            'matchResults', 'exceptions', 'duplicateOf:id,number', 'integrations',
        ]));
    }

    public function update(Request $request, VendorInvoice $vendorInvoice): JsonResponse
    {
        $data = $request->validate([
            'due_date' => ['nullable', 'date'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->successResponse($this->invoices->update($vendorInvoice, $data), 'Invoice diperbarui');
    }

    /** Jalankan 3-way matching (§29). */
    public function match(Request $request, VendorInvoice $vendorInvoice): JsonResponse
    {
        return $this->successResponse(
            $this->matching->match($vendorInvoice, $request->user()?->id),
            'Matching dijalankan',
        );
    }

    /** Override matching dengan alasan wajib (§53). */
    public function override(Request $request, VendorInvoice $vendorInvoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->successResponse(
            $this->matching->override($vendorInvoice, $data['reason'], $request->user()?->id),
            'Override matching dicatat',
        );
    }

    public function exceptions(Request $request): JsonResponse
    {
        $page = InvoiceException::query()
            ->with(['invoice:id,number,vendor_id,total', 'assignedTo:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->boolean('open_only'), fn ($q) => $q->open())
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        return $this->successResponse($page);
    }

    public function investigate(Request $request, InvoiceException $exception): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string']]);

        return $this->successResponse(
            $this->matching->startInvestigation($exception, $request->user()?->id, $data['notes'] ?? null),
            'Investigasi dimulai',
        );
    }

    public function resolve(Request $request, InvoiceException $exception): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'string', 'max:1000'],
            'approve' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse(
            $this->matching->resolve($exception, $request->user()?->id, $data['resolution'], (bool) ($data['approve'] ?? false)),
            'Pengecualian diselesaikan',
        );
    }

    /** Setujui invoice (setelah pengecualian selesai) (§32). */
    public function approve(Request $request, VendorInvoice $vendorInvoice): JsonResponse
    {
        return $this->successResponse(
            $this->invoices->approve($vendorInvoice, $request->user()?->id),
            'Invoice disetujui',
        );
    }

    /** Serahkan ke Accounting (§32, §33) — idempoten. */
    public function handoff(VendorInvoice $vendorInvoice): JsonResponse
    {
        return $this->successResponse($this->handoff->handoff($vendorInvoice), 'Invoice diserahkan ke Accounting');
    }

    public function retryHandoff(VendorInvoice $vendorInvoice): JsonResponse
    {
        return $this->successResponse($this->handoff->retry($vendorInvoice), 'Percobaan ulang handoff dijalankan');
    }

    public function syncStatus(VendorInvoice $vendorInvoice): JsonResponse
    {
        return $this->successResponse($this->handoff->syncStatus($vendorInvoice), 'Status disinkronkan');
    }

    /** Status pembayaran (dibaca dari Accounting, bukan disimpan) (§34). */
    public function paymentStatus(VendorInvoice $vendorInvoice): JsonResponse
    {
        return $this->successResponse($this->invoices->paymentStatus($vendorInvoice));
    }
}
