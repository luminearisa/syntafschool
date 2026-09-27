<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alokasi pembayaran (§15–§18 Phase 2) — berapa dari satu pembayaran diterapkan
 * ke satu invoice. Sumber kebenaran penerapan; `invoice.paid_amount` adalah
 * cache = SUM(amount) per invoice.
 */
class FinancePaymentAllocation extends BaseModel
{
    protected $casts = [
        'payment_id' => 'integer',
        'invoice_id' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FinancePayment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }
}
