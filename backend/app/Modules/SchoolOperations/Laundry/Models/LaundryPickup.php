<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengambilan di counter (§43) dengan verifikasi penerima (Rule 19).
 */
class LaundryPickup extends Model
{
    protected $table = 'laundry_pickups';

    protected $guarded = ['id'];

    protected $casts = [
        'picked_up_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }
}
