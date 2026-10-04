<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil QC (§38): checklist + PASSED/FAILED/REWORK.
 */
class LaundryQualityCheck extends Model
{
    protected $table = 'laundry_quality_checks';

    protected $guarded = ['id'];

    protected $casts = [
        'checklist' => 'array',
        'checked_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }
}
