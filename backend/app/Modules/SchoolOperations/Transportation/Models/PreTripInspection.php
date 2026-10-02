<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreTripInspection extends Model
{

    protected $table = 'pre_trip_inspections';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'vehicle_id' => 'integer',
        'driver_profile_id' => 'integer',
        'inspected_at' => 'datetime',
        'critical_failure' => 'boolean',
        'blocks_dispatch' => 'boolean',
        'items_checked' => 'integer',
        'items_failed' => 'integer',
        'odometer' => 'decimal:2',
        'offline_sync' => 'boolean',
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
    public function items(): HasMany
    {
        return $this->hasMany(PreTripInspectionItem::class, 'inspection_id');
    }
}
