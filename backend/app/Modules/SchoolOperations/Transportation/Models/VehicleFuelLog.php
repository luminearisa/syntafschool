<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleFuelLog extends Model
{

    protected $table = 'vehicle_fuel_logs';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'vehicle_id' => 'integer',
        'trip_id' => 'integer',
        'driver_profile_id' => 'integer',
        'fuel_quantity' => 'decimal:2',
        'fuel_cost' => 'decimal:2',
        'price_per_unit' => 'decimal:2',
        'odometer' => 'decimal:2',
        'fuel_date' => 'date',
        'recorded_by' => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleProfile::class, 'vehicle_id');
    }
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }
}
