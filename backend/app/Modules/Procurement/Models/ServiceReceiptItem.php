<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris penerimaan jasa. Tabel: `proc_service_receipt_items`. */
class ServiceReceiptItem extends ProcurementModel
{
    protected $table = 'proc_service_receipt_items';

    protected $casts = [
        'quantity' => 'decimal:4',
        'hours' => 'decimal:2',
        'completion_percentage' => 'decimal:2',
        'accepted_quantity' => 'decimal:4',
    ];

    public function serviceReceipt(): BelongsTo
    {
        return $this->belongsTo(ServiceReceipt::class, 'service_receipt_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
