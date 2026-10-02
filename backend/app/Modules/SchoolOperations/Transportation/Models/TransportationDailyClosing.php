<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;

class TransportationDailyClosing extends Model
{

    protected $table = 'transportation_daily_closings';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'closing_date' => 'date',
        'campus_id' => 'integer',
        'trips_planned' => 'integer',
        'trips_completed' => 'integer',
        'trips_cancelled' => 'integer',
        'passengers_transported' => 'integer',
        'no_shows' => 'integer',
        'incidents' => 'integer',
        'total_distance_km' => 'decimal:2',
        'total_fuel_cost' => 'decimal:2',
        'total_operational_cost' => 'decimal:2',
        'cost_per_passenger' => 'decimal:2',
        'on_time_rate' => 'decimal:2',
        'summary' => 'array',
        'closed_by' => 'integer',
        'closed_at' => 'datetime',
        'reopened_by' => 'integer',
        'reopened_at' => 'datetime',
    ];

}
