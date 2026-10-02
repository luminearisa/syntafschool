<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trip extends Model
{
    use SoftDeletes;
    protected $table = 'trips';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'route_id' => 'integer',
        'route_version_id' => 'integer',
        'schedule_id' => 'integer',
        'service_id' => 'integer',
        'campus_id' => 'integer',
        'trip_date' => 'date',
        'planned_start_at' => 'datetime',
        'planned_end_at' => 'datetime',
        'actual_start_at' => 'datetime',
        'actual_end_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'dispatched_by' => 'integer',
        'is_ad_hoc' => 'boolean',
        'event_reference_id' => 'integer',
        'vehicle_id' => 'integer',
        'driver_profile_id' => 'integer',
        'planned_passengers' => 'integer',
        'boarded_count' => 'integer',
        'dropped_off_count' => 'integer',
        'no_show_count' => 'integer',
        'start_odometer' => 'decimal:2',
        'end_odometer' => 'decimal:2',
        'distance_km' => 'decimal:2',
        'delay_minutes' => 'integer',
        'dispatch_validation' => 'array',
        'override_by' => 'integer',
        'cancelled_by' => 'integer',
        'cancelled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'route_id');
    }
    public function routeVersion(): BelongsTo
    {
        return $this->belongsTo(RouteVersion::class, 'route_version_id');
    }
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TransportationSchedule::class, 'schedule_id');
    }
    public function service(): BelongsTo
    {
        return $this->belongsTo(TransportationService::class, 'service_id');
    }
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleProfile::class, 'vehicle_id');
    }
    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }
    public function manifest(): HasMany
    {
        return $this->hasMany(TripManifest::class, 'trip_id');
    }
    public function passengers(): HasMany
    {
        return $this->hasMany(TripPassenger::class, 'trip_id');
    }
    public function stops(): HasMany
    {
        return $this->hasMany(TripStop::class, 'trip_id');
    }
    public function assignments(): HasMany
    {
        return $this->hasMany(TripAssignment::class, 'trip_id');
    }
    public function boardingEvents(): HasMany
    {
        return $this->hasMany(BoardingEvent::class, 'trip_id');
    }
    public function dropoffEvents(): HasMany
    {
        return $this->hasMany(DropoffEvent::class, 'trip_id');
    }
    public function incidents(): HasMany
    {
        return $this->hasMany(TransportationIncident::class, 'trip_id');
    }
    public function delayReports(): HasMany
    {
        return $this->hasMany(TripDelayReport::class, 'trip_id');
    }
    public function costs(): HasMany
    {
        return $this->hasMany(TransportationCost::class, 'trip_id');
    }
    public function fuelLogs(): HasMany
    {
        return $this->hasMany(VehicleFuelLog::class, 'trip_id');
    }
    public function transfers(): HasMany
    {
        return $this->hasMany(PassengerTransfer::class, 'trip_id');
    }
}
