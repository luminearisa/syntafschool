<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Procurement\Application\Services\ReceivingService;
use App\Modules\Procurement\Application\Services\ServiceReceiptService;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\ServiceReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Penerimaan barang, inspeksi, dan penerimaan jasa (§22–§27, §56). */
class ReceivingController extends BaseApiController
{
    public function __construct(
        private readonly ReceivingService $receiving,
        private readonly ServiceReceiptService $services,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->receiving->paginate(
            $request->only(['purchase_order_id', 'vendor_id', 'status', 'from', 'to']),
            (int) $request->query('per_page', 20),
        );

        return $this->successResponse($page);
    }

    /** Catat penerimaan barang untuk sebuah PO (§22, §23). */
    public function store(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate([
            'receipt_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:150'],
            'delivery_note_number' => ['nullable', 'string', 'max:60'],
            'courier' => ['nullable', 'string', 'max:120'],
            'driver' => ['nullable', 'string', 'max:120'],
            'vehicle_number' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:proc_purchase_order_items,id'],
            'items.*.delivered_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.accepted_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.rejected_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.damaged_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.batch_number' => ['nullable', 'string', 'max:60'],
            'items.*.serial_number' => ['nullable', 'string', 'max:60'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $data['received_by'] = $request->user()?->id;

        return $this->createdResponse($this->receiving->receive($purchaseOrder, $data), 'Penerimaan barang dicatat');
    }

    public function show(GoodsReceipt $goodsReceipt): JsonResponse
    {
        return $this->successResponse($goodsReceipt->load([
            'vendor:id,legal_name', 'purchaseOrder:id,number', 'items.purchaseOrderItem:id,description,unit', 'items.inspections.inspector:id,name',
        ]));
    }

    /** Catat hasil inspeksi mutu (§24). */
    public function inspect(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $data = $request->validate([
            'goods_receipt_item_id' => ['required', 'integer', 'exists:proc_goods_receipt_items,id'],
            'status' => ['required', 'in:PENDING,PASSED,PARTIAL,FAILED'],
            'checklist' => ['nullable', 'array'],
            'defect_notes' => ['nullable', 'string'],
            'rejection_reason' => ['nullable', 'string', 'max:500'],
            'photo_path' => ['nullable', 'string', 'max:255'],
        ]);

        $data['inspector_id'] = $request->user()?->id;

        return $this->createdResponse($this->receiving->inspect($goodsReceipt, $data), 'Hasil inspeksi dicatat');
    }

    // ---------------- Penerimaan jasa ----------------

    public function serviceReceipts(Request $request): JsonResponse
    {
        $page = $this->services->paginate(
            $request->only(['purchase_order_id', 'status']),
            (int) $request->query('per_page', 20),
        );

        return $this->successResponse($page);
    }

    public function storeServiceReceipt(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate([
            'service_period_start' => ['nullable', 'date'],
            'service_period_end' => ['nullable', 'date'],
            'milestone' => ['nullable', 'string', 'max:150'],
            'completion_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'responsible_officer_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:proc_purchase_order_items,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.hours' => ['nullable', 'numeric', 'min:0'],
            'items.*.completion_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return $this->createdResponse($this->services->create($purchaseOrder, $data), 'Penerimaan jasa dibuat');
    }

    public function acceptServiceReceipt(Request $request, ServiceReceipt $serviceReceipt): JsonResponse
    {
        return $this->successResponse(
            $this->services->accept($serviceReceipt, $request->user()?->id),
            'Penerimaan jasa diterima',
        );
    }

    public function rejectServiceReceipt(Request $request, ServiceReceipt $serviceReceipt): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->successResponse($this->services->reject($serviceReceipt, $data['reason']), 'Penerimaan jasa ditolak');
    }
}
