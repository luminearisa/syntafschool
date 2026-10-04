<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris item invoice vendor. Tabel: `proc_vendor_invoice_items`. */
class VendorInvoiceItem extends ProcurementModel
{
    protected $table = 'proc_vendor_invoice_items';

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class, 'vendor_invoice_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class, 'goods_receipt_item_id');
    }
}
