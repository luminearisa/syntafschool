<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengantaran ke asrama (§43/§44) dengan konfirmasi penerima.
 */
class LaundryDelivery extends Model
{
    protected $table = 'laundry_deliveries';

    protected $guarded = ['id'];

    protected $casts = [
        'delivered_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }
}
