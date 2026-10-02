<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransportationRoute extends Model
{
    use SoftDeletes;
    protected $table = 'transportation_routes';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'campus_id' => 'integer',
        'service_id' => 'integer',
        'origin_location_id' => 'integer',
        'destination_location_id' => 'integer',
        'distance_km' => 'decimal:2',
        'estimated_duration_minutes' => 'integer',
        'default_vehicle_capacity' => 'integer',
        'max_stops' => 'integer',
        'max_duration_minutes' => 'integer',
        'current_version_id' => 'integer',
        'version_number' => 'integer',
        'active' => 'boolean',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(TransportationService::class, 'service_id');
    }
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(RouteVersion::class, 'current_version_id');
    }
    public function versions(): HasMany
    {
        return $this->hasMany(RouteVersion::class, 'route_id');
    }
    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'route_id');
    }
    public function schedules(): HasMany
    {
        return $this->hasMany(TransportationSchedule::class, 'route_id');
    }
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_id');
    }
    public function assignments(): HasMany
    {
        return $this->hasMany(TransportationAssignment::class, 'route_id');
    }
    public function waitlists(): HasMany
    {
        return $this->hasMany(TransportationWaitlist::class, 'route_id');
    }
}
