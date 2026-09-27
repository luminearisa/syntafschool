<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris purchase request (§37).
 */
class FinancePurchaseRequestItem extends BaseModel
{
    protected $casts = [
        'quantity' => 'integer',
        'estimated_price' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(FinancePurchaseRequest::class, 'purchase_request_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }
}
