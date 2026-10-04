<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Contracts\AssetProviderInterface;
use App\Modules\Procurement\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Procurement\Domain\Enums\InspectionStatus;
use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Domain\Enums\ReceiptStatus;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\GoodsReceiptInspection;
use App\Modules\Procurement\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Penerimaan barang & inspeksi (§22–§26).
 *
 * Procurement **tidak** membuat kartu stok maupun aset sendiri: setelah lolos
 * inspeksi, penerimaan diserahkan ke Inventory/Asset lewat kontrak (§25, §26).
 * Toleransi over/under delivery dibaca dari konfigurasi (§23).
 */
class ReceivingService
{
    public function __construct(
        private readonly ProcurementNumberingService $numbering,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementEventService $events,
        private readonly ProcurementControlService $control,
        private readonly PurchaseOrderService $orders,
        private readonly IntegrationService $integrations,
        private readonly InventoryProviderInterface $inventory,
        private readonly AssetProviderInterface $assets,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return GoodsReceipt::query()
            ->with(['vendor:id,legal_name', 'purchaseOrder:id,number', 'items'])
            ->when($filters['purchase_order_id'] ?? null, fn ($q, $id) => $q->where('purchase_order_id', (int) $id))
            ->when($filters['vendor_id'] ?? null, fn ($q, $id) => $q->where('vendor_id', (int) $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('receipt_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('receipt_date', '<=', $to))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Catat penerimaan barang (§22, §23).
     *
     * @param  array<string, mixed>  $data  {receipt_date, warehouse_id, items: [{purchase_order_item_id, delivered_quantity, accepted_quantity, rejected_quantity, damaged_quantity, batch_number, serial_number, expiry_date}]}
     */
    public function receive(PurchaseOrder $order, array $data): GoodsReceipt
    {
        if (! $order->statusEnum()->isIssued() && $order->statusEnum() !== \App\Modules\Procurement\Domain\Enums\PurchaseOrderStatus::APPROVED) {
            throw ProcurementException::because('PO belum diterbitkan; penerimaan belum dapat dicatat.', 'PO_NOT_ISSUED');
        }

        return DB::transaction(function () use ($order, $data): GoodsReceipt {
            $items = (array) ($data['items'] ?? []);
            unset($data['items']);

            $receipt = GoodsReceipt::query()->create([
                'number' => $this->numbering->next(ProcurementNumberingService::CODE_GOODS_RECEIPT),
                'purchase_order_id' => $order->id,
                'vendor_id' => $order->vendor_id,
                'receipt_date' => $data['receipt_date'] ?? now()->toDateString(),
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'location' => $data['location'] ?? null,
                'received_by' => $data['received_by'] ?? \Illuminate\Support\Facades\Auth::id(),
                'delivery_note_number' => $data['delivery_note_number'] ?? null,
                'courier' => $data['courier'] ?? null,
                'driver' => $data['driver'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'status' => ReceiptStatus::RECEIVED->value,
                'notes' => $data['notes'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
            ]);

            $tolerance = $this->control->receivingTolerance();
            $overTolerance = (float) ($tolerance['over_delivery_tolerance_percent'] ?? 0);
            $requireInspection = (bool) ($tolerance['require_inspection'] ?? false);

            $receivedByItem = [];

            foreach ($items as $row) {
                $poItem = $order->items()->findOrFail($row['purchase_order_item_id']);

                $delivered = (float) ($row['delivered_quantity'] ?? 0);
                $ordered = (float) $poItem->quantity;
                $remaining = $poItem->remainingQuantity();

                // Toleransi over delivery (§23).
                if ($delivered > $remaining * (1 + $overTolerance / 100) + 0.0001) {
                    throw ProcurementException::because(
                        "Jumlah diterima melebihi sisa PO untuk \"{$poItem->description}\" (sisa {$remaining}, diterima {$delivered}).",
                        'OVER_DELIVERY',
                    );
                }

                $accepted = (float) ($row['accepted_quantity'] ?? $delivered);
                $rejected = (float) ($row['rejected_quantity'] ?? 0);
                $damaged = (float) ($row['damaged_quantity'] ?? 0);
                $backorder = max(0, round($ordered - (float) $poItem->received_quantity - $delivered, 4));

                $receipt->items()->create([
                    'purchase_order_item_id' => $poItem->id,
                    'ordered_quantity' => $ordered,
                    'delivered_quantity' => $delivered,
                    'accepted_quantity' => $accepted,
                    'rejected_quantity' => $rejected,
                    'damaged_quantity' => $damaged,
                    'backorder_quantity' => $backorder,
                    'unit' => $poItem->unit,
                    'batch_number' => $row['batch_number'] ?? null,
                    'serial_number' => $row['serial_number'] ?? null,
                    'expiry_date' => $row['expiry_date'] ?? null,
                    'inspection_status' => $requireInspection ? InspectionStatus::PENDING->value : InspectionStatus::PASSED->value,
                    'notes' => $row['notes'] ?? null,
                ]);

                $receivedByItem[$poItem->id] = $accepted;
            }

            $this->orders->applyReceipt($order, $receivedByItem);

            $this->audit->record(ProcurementAuditAction::RECEIVE, $receipt, null, $receipt->toArray());
            $this->events->dispatch('GoodsReceived', $receipt, ['number' => $receipt->number, 'purchase_order_id' => $order->id]);

            $receipt = $receipt->fresh(['items']);

            if (! $requireInspection) {
                $this->handoffReceipt($receipt);
            }

            return $receipt;
        });
    }

    /** Catat hasil inspeksi mutu (§24). @param array<string, mixed> $data */
    public function inspect(GoodsReceipt $receipt, array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $data): GoodsReceipt {
            $item = $receipt->items()->findOrFail($data['goods_receipt_item_id']);

            $inspection = GoodsReceiptInspection::query()->create([
                'goods_receipt_item_id' => $item->id,
                'inspector_id' => $data['inspector_id'] ?? \Illuminate\Support\Facades\Auth::id(),
                'status' => $data['status'] ?? InspectionStatus::PASSED->value,
                'checklist' => $data['checklist'] ?? null,
                'defect_notes' => $data['defect_notes'] ?? null,
                'rejection_reason' => $data['rejection_reason'] ?? null,
                'photo_path' => $data['photo_path'] ?? null,
                'inspected_at' => now(),
            ]);

            $status = InspectionStatus::tryFrom((string) $inspection->status) ?? InspectionStatus::PASSED;

            $item->forceFill([
                'inspection_status' => $status->value,
                'accepted_quantity' => $status === InspectionStatus::FAILED ? 0 : (float) $item->accepted_quantity,
                'rejected_quantity' => $status === InspectionStatus::FAILED
                    ? (float) $item->delivered_quantity
                    : (float) $item->rejected_quantity,
            ])->save();

            $receipt->forceFill([
                'status' => $status === InspectionStatus::FAILED ? ReceiptStatus::REJECTED->value : ReceiptStatus::INSPECTED->value,
                'inspected_at' => now(),
            ])->save();

            $this->audit->record(ProcurementAuditAction::INSPECT, $inspection, null, $inspection->toArray());

            if ($status === InspectionStatus::FAILED) {
                $this->events->dispatch('GoodsRejected', $receipt, ['goods_receipt_item_id' => $item->id, 'reason' => $inspection->rejection_reason]);
            }

            $receipt = $receipt->fresh(['items']);

            if ($status !== InspectionStatus::FAILED && $receipt->items->every(fn ($i) => $i->inspection_status !== InspectionStatus::PENDING)) {
                $this->handoffReceipt($receipt);
            }

            return $receipt;
        });
    }

    /**
     * Serahkan penerimaan yang diterima ke Inventory/Asset (§25, §26).
     * Idempotensi dijaga `idempotency_key` per baris penerimaan.
     */
    protected function handoffReceipt(GoodsReceipt $receipt): void
    {
        $receipt->loadMissing('items.purchaseOrderItem');

        foreach ($receipt->items as $item) {
            if ((float) $item->accepted_quantity <= 0) {
                continue;
            }

            $poItem = $item->purchaseOrderItem;

            $payload = [
                'reference' => $receipt->number,
                'goods_receipt_item_id' => $item->id,
                'purchase_order_item_id' => $poItem?->id,
                'item_id' => $poItem?->item_id,
                'inventory_item_ref' => $poItem?->item?->inventory_item_ref,
                'warehouse_id' => $receipt->warehouse_id,
                'quantity' => (float) $item->accepted_quantity,
                'unit' => $item->unit,
                'unit_price' => (float) ($poItem?->unit_price ?? 0),
                'batch_number' => $item->batch_number,
                'serial_number' => $item->serial_number,
                'expiry_date' => $item->expiry_date?->toDateString(),
                'received_at' => $receipt->receipt_date?->toDateString(),
            ];

            if ($poItem?->is_inventory) {
                $key = 'PROC-INV-'.$item->id;
                $integration = $this->integrations->record($item, 'INVENTORY', $receipt->number, $key, $payload);
                $this->integrations->markSent($integration);
                $this->integrations->markResult($integration, $this->inventory->receive($payload));
            }

            if ($poItem?->is_asset) {
                $key = 'PROC-AST-'.$item->id;
                $integration = $this->integrations->record($item, 'ASSET', $receipt->number, $key, $payload);
                $this->integrations->markSent($integration);
                $this->integrations->markResult($integration, $this->assets->registerAcquisition($payload));
            }
        }

        $accepted = $receipt->items->every(fn ($i) => (float) $i->rejected_quantity === 0.0);

        $receipt->forceFill([
            'status' => $accepted ? ReceiptStatus::ACCEPTED->value : ReceiptStatus::PARTIALLY_ACCEPTED->value,
        ])->save();
    }
}
