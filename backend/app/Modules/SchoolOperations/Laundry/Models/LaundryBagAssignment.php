<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penautan tas ke santri (§16): Student → Bag → Order → Return.
 */
class LaundryBagAssignment extends Model
{
    protected $table = 'laundry_bag_assignments';

    protected $guarded = ['id'];

    protected $casts = [
        'assigned_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function bag(): BelongsTo
    {
        return $this->belongsTo(LaundryBag::class, 'laundry_bag_id');
    }
}
