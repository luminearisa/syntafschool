<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportationSchedule extends Model
{

    protected $table = 'transportation_schedules';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'route_id' => 'integer',
        'service_id' => 'integer',
        'days_of_week' => 'array',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'specific_dates' => 'array',
        'lead_minutes' => 'integer',
        'default_vehicle_id' => 'integer',
        'default_driver_profile_id' => 'integer',
        'holiday_exception' => 'boolean',
        'active' => 'boolean',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'route_id');
    }
    public function service(): BelongsTo
    {
        return $this->belongsTo(TransportationService::class, 'service_id');
    }
    public function defaultVehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleProfile::class, 'default_vehicle_id');
    }
    public function defaultDriver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'default_driver_profile_id');
    }
    public function exceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class, 'schedule_id');
    }
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'schedule_id');
    }
}
