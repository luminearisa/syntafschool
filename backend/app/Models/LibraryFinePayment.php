<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembayaran denda.
 *
 * Memakai master `payment_methods` yang sudah ada — modul Library tidak
 * membuat sistem pembayaran/akuntansi sendiri.
 */
class LibraryFinePayment extends BaseModel
{
    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'date:Y-m-d',
    ];

    public function fine(): BelongsTo
    {
        return $this->belongsTo(LibraryFine::class, 'fine_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}