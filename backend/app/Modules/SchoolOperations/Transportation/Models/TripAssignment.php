<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripAssignment extends Model
{

    protected $table = 'trip_assignments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'vehicle_id' => 'integer',
        'driver_profile_id' => 'integer',
        'replaces_assignment_id' => 'integer',
        'rental_id' => 'integer',
        'validation' => 'array',
        'assigned_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'assigned_at' => 'datetime',
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
