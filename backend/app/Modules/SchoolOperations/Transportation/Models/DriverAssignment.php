<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverAssignment extends Model
{

    protected $table = 'driver_assignments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'driver_profile_id' => 'integer',
        'trip_id' => 'integer',
        'vehicle_id' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'replaces_assignment_id' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'assigned_by' => 'integer',
        'validation' => 'array',
    ];

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleProfile::class, 'vehicle_id');
    }
}
