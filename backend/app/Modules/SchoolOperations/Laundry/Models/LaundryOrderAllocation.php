<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Split free/paid satu order (§12). Baris FREE tertaut ke ledger entitlement (Rule 21).
 */
class LaundryOrderAllocation extends Model
{
    protected $table = 'laundry_order_allocations';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'amount' => 'decimal:2',
        'unit_price' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }
}
