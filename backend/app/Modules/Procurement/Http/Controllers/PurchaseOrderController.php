<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Procurement\Application\Services\PurchaseOrderService;
use App\Modules\Procurement\Domain\Enums\ProcurementItemType;
use App\Modules\Procurement\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase Order, amandemen, dan konfirmasi vendor (§17–§21). */
class PurchaseOrderController extends BaseApiController
{
    public function __construct(private readonly PurchaseOrderService $orders)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->orders->paginate(
            $request->only(['search', 'status', 'vendor_id', 'from', 'to']),
            (int) $request->query('per_page', 20),
        );

        return $this->successResponse($page);
    }

    /** Buat PO langsung (pembelian langsung/darurat). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:proc_vendors,id'],
            'expected_delivery_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_terms' => ['nullable', 'string', 'max:60'],
            'delivery_terms' => ['nullable', 'string', 'max:120'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'shipping_address' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer'],
            'cost_center_id' => ['nullable', 'integer'],
            'project_reference' => ['nullable', 'string', 'max:60'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'terms_conditions' => ['nullable', 'string'],
            'overridden' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:proc_items,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.item_type' => ['nullable', Rule::in(ProcurementItemType::values())],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.is_asset' => ['nullable', 'boolean'],
            'items.*.is_inventory' => ['nullable', 'boolean'],
            'items.*.required_delivery_date' => ['nullable', 'date'],
        ]);

        $data['requester_id'] = $request->user()?->id;
        $data['created_by'] = $request->user()?->id;

        return $this->createdResponse($this->orders->create($data, (bool) ($data['overridden'] ?? false)), 'PO dibuat');
    }

    /** Buat PO dari pemilihan vendor (§17). */
    public function storeFromSelection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'selection_id' => ['required', 'integer', 'exists:proc_vendor_selections,id'],
            'expected_delivery_date' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string', 'max:60'],
            'delivery_terms' => ['nullable', 'string', 'max:120'],
            'shipping_address' => ['nullable', 'string', 'max:255'],
            'cost_center_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'overridden' => ['nullable', 'boolean'],
        ]);

        $data['requester_id'] = $request->user()?->id;
        $data['created_by'] = $request->user()?->id;

        return $this->createdResponse(
            $this->orders->createFromSelection((int) $data['selection_id'], $data),
            'PO dibuat dari pemilihan vendor',
        );
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->successResponse($purchaseOrder->load([
            'vendor:id,code,legal_name', 'items.item:id,name,sku', 'versions', 'approvals.approver:id,name',
            'confirmations', 'goodsReceipts:id,number,purchase_order_id,receipt_date,status', 'invoices:id,number,vendor_invoice_number,status,total',
        ]));
    }

    public function submit(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->successResponse($this->orders->submitForApproval($purchaseOrder), 'PO diajukan untuk persetujuan');
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);

        return $this->successResponse(
            $this->orders->approve($purchaseOrder, $request->user()?->id, $data['comment'] ?? null),
            'PO disetujui',
        );
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->successResponse($this->orders->reject($purchaseOrder, $data['reason']), 'PO ditolak');
    }

    public function issue(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->successResponse(
            $this->orders->issue($purchaseOrder, $request->boolean('budget_overridden')),
            'PO diterbitkan',
        );
    }

    /** Amandemen PO — versi baru, histori tetap utuh (§20). */
    public function amend(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'material' => ['nullable', 'boolean'],
            'expected_delivery_date' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string', 'max:60'],
            'delivery_terms' => ['nullable', 'string', 'max:120'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
        ]);

        $version = $this->orders->amend(
            $purchaseOrder,
            $data,
            $data['reason'],
            (bool) ($data['material'] ?? false),
            $request->user()?->id,
        );

        return $this->createdResponse($version, 'Amandemen PO dibuat');
    }

    /** Konfirmasi vendor (§21). */
    public function confirm(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:PENDING,ACCEPTED,PARTIALLY_ACCEPTED,REJECTED,REVISED'],
            'revised_delivery_date' => ['nullable', 'date'],
            'revised_notes' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->createdResponse(
            $this->orders->confirm($purchaseOrder, $data, $request->user()?->id),
            'Konfirmasi vendor dicatat',
        );
    }

    public function close(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->successResponse($this->orders->close($purchaseOrder), 'PO ditutup');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->successResponse($this->orders->cancel($purchaseOrder, $data['reason']), 'PO dibatalkan');
    }

    /** Riwayat versi PO (§20, §67). */
    public function versions(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->successResponse($purchaseOrder->versions()->with(['requestedBy:id,name', 'approvedBy:id,name'])->get());
    }
}
