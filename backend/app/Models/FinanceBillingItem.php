<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris billing (§13): satu jenis tagihan + nominal + potongan.
 */
class FinanceBillingItem extends BaseModel
{
    protected $casts = [
        'amount' => 'decimal:2',
        'quantity' => 'integer',
        'discount_amount' => 'decimal:2',
        'scholarship_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function billing(): BelongsTo
    {
        return $this->belongsTo(FinanceBilling::class, 'billing_id');
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FinanceFeeType::class, 'fee_type_id');
    }
}
