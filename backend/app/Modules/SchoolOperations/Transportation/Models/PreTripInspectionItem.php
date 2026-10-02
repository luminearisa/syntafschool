<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreTripInspectionItem extends Model
{

    protected $table = 'pre_trip_inspection_items';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'inspection_id' => 'integer',
        'mandatory' => 'boolean',
        'critical' => 'boolean',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(PreTripInspection::class, 'inspection_id');
    }
}
