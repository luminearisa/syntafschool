<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripStop extends Model
{

    protected $table = 'trip_stops';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'route_stop_id' => 'integer',
        'sequence' => 'integer',
        'latitude' => 'decimal:2',
        'longitude' => 'decimal:2',
        'planned_arrival_at' => 'datetime',
        'planned_departure_at' => 'datetime',
        'estimated_arrival_at' => 'datetime',
        'actual_arrival_at' => 'datetime',
        'actual_departure_at' => 'datetime',
        'arrival_delay_minutes' => 'integer',
        'departure_delay_minutes' => 'integer',
        'planned_passengers' => 'integer',
        'boarded_count' => 'integer',
        'dropped_off_count' => 'integer',
        'no_show_count' => 'integer',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
    public function routeStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'route_stop_id');
    }
    public function events(): HasMany
    {
        return $this->hasMany(TripStopEvent::class, 'trip_stop_id');
    }
}
