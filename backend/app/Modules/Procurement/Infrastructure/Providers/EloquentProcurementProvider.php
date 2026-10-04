<?php

namespace App\Modules\Procurement\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\ProcurementProviderInterface;
use App\Modules\Procurement\Application\Services\PurchaseRequestService;
use App\Modules\Procurement\Models\GoodsReceiptItem;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseRequest;

/**
 * Adapter untuk modul Asset: menjembatani permintaan pengadaan & penerimaan
 * barang yang akan menjadi aset.
 *
 * Modul Asset tidak membuat PO sendiri; ia menitipkan permintaan lewat kontrak
 * ini dan menerima kabar penerimaan untuk mendaftarkan aset.
 */
class EloquentProcurementProvider implements ProcurementProviderInterface
{
    public function __construct(private readonly PurchaseRequestService $requests)
    {
    }

    public function request(array $payload): array
    {
        $request = $this->requests->createFromExternal($payload);

        return [
            'reference' => $request->number,
            'id' => $request->id,
            'status' => $request->status instanceof \BackedEnum ? $request->status->value : $request->status,
        ];
    }

    public function status(string $reference): ?array
    {
        $request = PurchaseRequest::query()->where('number', $reference)->first();

        if ($request !== null) {
            return [
                'type' => 'PURCHASE_REQUEST',
                'reference' => $request->number,
                'status' => $request->status instanceof \BackedEnum ? $request->status->value : $request->status,
                'total' => (float) $request->estimated_total,
            ];
        }

        $order = PurchaseOrder::query()->where('number', $reference)->first();

        if ($order !== null) {
            return [
                'type' => 'PURCHASE_ORDER',
                'reference' => $order->number,
                'status' => $order->status instanceof \BackedEnum ? $order->status->value : $order->status,
                'total' => (float) $order->grand_total,
            ];
        }

        return null;
    }

    public function receipts(array $filters): array
    {
        return GoodsReceiptItem::query()
            ->with(['goodsReceipt:id,number,purchase_order_id,receipt_date,status', 'purchaseOrderItem:id,description,unit,unit_price,is_asset'])
            ->whereHas('purchaseOrderItem', fn ($q) => $q->where('is_asset', true))
            ->whereNull('recognized_asset_codes')
            ->where('accepted_quantity', '>', 0)
            ->when($filters['goods_receipt_id'] ?? null, fn ($q, $id) => $q->where('goods_receipt_id', (int) $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->get()
            ->map(fn (GoodsReceiptItem $item): array => [
                'id' => $item->id,
                'receipt_number' => $item->goodsReceipt?->number,
                'po_number' => $item->purchaseOrderItem?->purchase_order_id,
                'description' => $item->purchaseOrderItem?->description,
                'accepted_quantity' => (float) $item->accepted_quantity,
                'unit' => $item->unit,
                'unit_price' => (float) ($item->purchaseOrderItem?->unit_price ?? 0),
                'receipt_date' => $item->goodsReceipt?->receipt_date?->toDateString(),
            ])
            ->all();
    }

    public function markRecognized(string $reference, array $assetCodes): array
    {
        // `reference` adalah nomor goods receipt; tandai baris aset pada receipt itu.
        $items = GoodsReceiptItem::query()
            ->whereHas('goodsReceipt', fn ($q) => $q->where('number', $reference))
            ->whereHas('purchaseOrderItem', fn ($q) => $q->where('is_asset', true))
            ->get();

        foreach ($items as $item) {
            $item->recognized_asset_codes = $assetCodes;
            $item->save();
        }

        return ['reference' => $reference, 'recognized' => $items->count(), 'asset_codes' => $assetCodes];
    }
}
