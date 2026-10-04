<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\Enums\ProcurementItemType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris item PO. Tabel: `proc_purchase_order_items`.
 *
 * `received_quantity` dan `invoiced_quantity` adalah akumulasi yang diperbarui
 * transaksional saat penerimaan/invoice, sehingga sisa yang belum diterima
 * selalu dapat dihitung ulang.
 */
class PurchaseOrderItem extends ProcurementModel
{
    protected $table = 'proc_purchase_order_items';

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'received_quantity' => 'decimal:4',
        'invoiced_quantity' => 'decimal:4',
        'required_delivery_date' => 'date',
        'is_asset' => 'boolean',
        'is_inventory' => 'boolean',
        'item_type' => ProcurementItemType::class,
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /** Sisa kuantitas yang belum diterima. */
    public function remainingQuantity(): float
    {
        return max(0, round((float) $this->quantity - (float) $this->received_quantity, 4));
    }
}
