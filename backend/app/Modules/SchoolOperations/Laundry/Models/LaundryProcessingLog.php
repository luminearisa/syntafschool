<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak transisi status order (Rule 28) — from/to/actor/reason.
 */
class LaundryProcessingLog extends Model
{
    protected $table = 'laundry_processing_logs';

    protected $guarded = ['id'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }
}
