<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Insiden laundry (§39/§40): hilang/rusak/noda + resolusi & kompensasi ber-approval (§41).
 */
class LaundryIncident extends Model
{
    protected $table = 'laundry_incidents';

    protected $guarded = ['id'];

    protected $casts = [
        'compensation_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LaundryOrderItem::class, 'laundry_order_item_id');
    }
}
