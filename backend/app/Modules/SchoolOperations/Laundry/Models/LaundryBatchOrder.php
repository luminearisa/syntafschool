<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot batch↔order dengan jejak siapa/kapan order dimasukkan.
 */
class LaundryBatchOrder extends Model
{
    protected $table = 'laundry_batch_orders';

    protected $guarded = ['id'];

    protected $casts = [
        'added_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LaundryBatch::class, 'laundry_batch_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }
}
