<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\Enums\InspectionStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Baris penerimaan barang. Tabel: `proc_goods_receipt_items`.
 *
 * Memisahkan `delivered` (yang datang), `accepted` (yang diterima baik),
 * `rejected`, `damaged`, dan `backorder` agar matching jumlah dapat diaudit.
 */
class GoodsReceiptItem extends ProcurementModel
{
    protected $table = 'proc_goods_receipt_items';

    protected $casts = [
        'ordered_quantity' => 'decimal:4',
        'delivered_quantity' => 'decimal:4',
        'accepted_quantity' => 'decimal:4',
        'rejected_quantity' => 'decimal:4',
        'damaged_quantity' => 'decimal:4',
        'backorder_quantity' => 'decimal:4',
        'expiry_date' => 'date',
        'recognized_asset_codes' => 'array',
        'inspection_status' => InspectionStatus::class,
    ];

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(GoodsReceiptInspection::class, 'goods_receipt_item_id');
    }
}
