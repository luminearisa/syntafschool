<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Percobaan pembayaran (§22). Unik (order, idempotency_key): replay request
 * mengembalikan hasil attempt yang sama, tidak pernah charge ganda.
 */
class LaundryPaymentAttempt extends Model
{
    protected $table = 'laundry_payment_attempts';

    protected $guarded = ['id'];

    protected $casts = [
        'attempt_number' => 'integer',
        'amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }
}
