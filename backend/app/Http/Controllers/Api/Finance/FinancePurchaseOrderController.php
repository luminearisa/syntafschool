<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinancePurchaseOrderRequest;
use App\Http\Resources\FinancePurchaseOrderResource;
use App\Models\FinancePurchaseOrder;
use App\Services\Finance\FinancePurchaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Purchase order (§38): buat (dari PR disetujui atau langsung) → ajukan →
 * approve → pesan → terima barang. Nilai keuangan diakui saat invoice vendor
 * diterima (menaut ke PO), bukan di sini — jadi PO tidak memposting jurnal.
 */
class FinancePurchaseOrderController extends BaseApiController
{
    public function __construct(private readonly FinancePurchaseService $purchasing) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinancePurchaseOrder::query()
            ->with(['vendor', 'purchaseRequest', 'costCenter', 'approvedBy', 'items'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('vendor_id'), fn (Builder $q) => $q->where('vendor_id', (int) $request->input('vendor_id')))
            ->when($request->filled('purchase_request_id'), fn (Builder $q) => $q->where('purchase_request_id', (int) $request->input('purchase_request_id')))
            ->when($request->boolean('open'), fn (Builder $q) => $q->open())
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where('po_number', 'like', "%$term%");
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinancePurchaseOrderResource::collection($query->get()), 'Daftar purchase order berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinancePurchaseOrderResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinancePurchaseOrder::STATUSES, 'status_labels' => FinancePurchaseOrder::STATUS_LABELS]]),
            'Daftar purchase order berhasil diambil',
        );
    }

    public function show(FinancePurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load(['vendor', 'purchaseRequest', 'costCenter', 'approvedBy', 'items']);

        return $this->successResponse(new FinancePurchaseOrderResource($purchaseOrder), 'Detail purchase order berhasil diambil');
    }

    public function store(FinancePurchaseOrderRequest $request): JsonResponse
    {
        $order = $this->purchasing->createOrder($request->validated(), $request->user());

        return $this->createdResponse(new FinancePurchaseOrderResource($order), 'Purchase order berhasil dibuat');
    }

    public function submit(Request $request, FinancePurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = $this->purchasing->submitOrder($purchaseOrder, $request->user());

        return $this->successResponse(new FinancePurchaseOrderResource($updated), 'Purchase order diajukan');
    }

    public function approve(Request $request, FinancePurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = $this->purchasing->approveOrder($purchaseOrder, $request->user());

        return $this->successResponse(new FinancePurchaseOrderResource($updated), 'Purchase order disetujui');
    }

    public function order(FinancePurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = $this->purchasing->markOrdered($purchaseOrder);

        return $this->successResponse(new FinancePurchaseOrderResource($updated), 'Purchase order ditandai dipesan');
    }

    /** Terima barang (§38): naikkan received_quantity per baris. */
    public function receive(Request $request, FinancePurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $this->purchasing->receive($purchaseOrder, $data['items'], $request->user());

        return $this->successResponse(new FinancePurchaseOrderResource($updated), 'Penerimaan barang dicatat');
    }

    public function cancel(FinancePurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = $this->purchasing->cancelOrder($purchaseOrder);

        return $this->successResponse(new FinancePurchaseOrderResource($updated), 'Purchase order dibatalkan');
    }
}
