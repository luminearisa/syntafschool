<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris purchase order (§38) dengan tracking kuantitas diterima.
 */
class FinancePurchaseOrderItem extends BaseModel
{
    protected $casts = [
        'quantity' => 'integer',
        'received_quantity' => 'integer',
        'price' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(FinancePurchaseOrder::class, 'purchase_order_id');
    }

    public function isFullyReceived(): bool
    {
        return $this->received_quantity >= $this->quantity;
    }
}
