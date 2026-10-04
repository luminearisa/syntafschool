<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refund wallet (§23, Rule 14) — selalu lewat WalletProvider, tercatat referensinya.
 */
class LaundryRefund extends Model
{
    protected $table = 'laundry_refunds';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(LaundryPayment::class, 'laundry_payment_id');
    }
}
