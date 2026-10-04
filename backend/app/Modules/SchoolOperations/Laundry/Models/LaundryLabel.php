<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Label digital order (§31): nomor + payload QR untuk ditempel di bag.
 */
class LaundryLabel extends Model
{
    protected $table = 'laundry_labels';

    protected $guarded = ['id'];

    protected $casts = [
        'printed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }
}
