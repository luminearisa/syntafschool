<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;

class TransportationAdjustment extends Model
{

    protected $table = 'transportation_adjustments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'reference_id' => 'integer',
        'before' => 'array',
        'after' => 'array',
        'requested_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
    ];

}
