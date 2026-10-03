<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Langkah proses per order (§35–§37, §42): washing/drying/ironing/folding/packaging.
 */
class LaundryProcessingStep extends Model
{
    protected $table = 'laundry_processing_steps';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'temperature_c' => 'decimal:1',
        'item_count' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'operator_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(LaundryMachine::class, 'laundry_machine_id');
    }
}
