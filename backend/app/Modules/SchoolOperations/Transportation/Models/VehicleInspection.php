<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleInspection extends Model
{

    protected $table = 'vehicle_inspections';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'vehicle_id' => 'integer',
        'inspection_date' => 'date',
        'next_inspection_date' => 'date',
        'work_order_id' => 'integer',
        'cost' => 'decimal:2',
        'inspected_by' => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleProfile::class, 'vehicle_id');
    }
}
