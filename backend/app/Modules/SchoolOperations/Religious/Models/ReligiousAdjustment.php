<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model tabel `religious_adjustments`.
 */
class ReligiousAdjustment extends Model
{
    protected $table = 'religious_adjustments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'entity_id' => 'integer',
        'from_value' => 'array',
        'to_value' => 'array',
        'requested_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'effective_date' => 'date',
    ];
}
