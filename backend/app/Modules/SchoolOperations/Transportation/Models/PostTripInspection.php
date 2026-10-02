<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostTripInspection extends Model
{

    protected $table = 'post_trip_inspections';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'vehicle_id' => 'integer',
        'driver_profile_id' => 'integer',
        'inspected_at' => 'datetime',
        'odometer' => 'decimal:2',
        'damage_found' => 'boolean',
        'lost_items_found' => 'boolean',
        'passenger_issues' => 'boolean',
        'incident_occurred' => 'boolean',
        'cleaning_required' => 'boolean',
        'maintenance_required' => 'boolean',
        'maintenance_reference_id' => 'integer',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleProfile::class, 'vehicle_id');
    }
    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }
}
