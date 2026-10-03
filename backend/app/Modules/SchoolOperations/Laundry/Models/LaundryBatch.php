<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Batch pemrosesan (§33): banyak order diproses satu mesin/operator sekaligus.
 */
class LaundryBatch extends Model
{
    protected $table = 'laundry_batches';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_weight' => 'decimal:3',
        'total_orders' => 'integer',
        'temperature_c' => 'decimal:1',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(LaundryMachine::class, 'laundry_machine_id');
    }

    public function orderRows(): HasMany
    {
        return $this->hasMany(LaundryBatchOrder::class);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(LaundryOrder::class, 'laundry_batch_orders', 'laundry_batch_id', 'laundry_order_id');
    }
}
