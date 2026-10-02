<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RouteStop extends Model
{
    use SoftDeletes;
    protected $table = 'route_stops';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'route_id' => 'integer',
        'route_version_id' => 'integer',
        'sequence' => 'integer',
        'location_id' => 'integer',
        'latitude' => 'decimal:2',
        'longitude' => 'decimal:2',
        'geofence_radius_meters' => 'integer',
        'pickup_allowed' => 'boolean',
        'dropoff_allowed' => 'boolean',
        'capacity' => 'integer',
        'default_dwell_minutes' => 'integer',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'route_id');
    }
    public function routeVersion(): BelongsTo
    {
        return $this->belongsTo(RouteVersion::class, 'route_version_id');
    }
}
