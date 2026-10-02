<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransportationIncident extends Model
{
    use SoftDeletes;
    protected $table = 'transportation_incidents';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'vehicle_id' => 'integer',
        'driver_profile_id' => 'integer',
        'student_id' => 'integer',
        'route_id' => 'integer',
        'stop_id' => 'integer',
        'occurred_at' => 'datetime',
        'latitude' => 'decimal:2',
        'longitude' => 'decimal:2',
        'injured_count' => 'integer',
        'passengers_involved' => 'boolean',
        'escalated' => 'boolean',
        'escalated_at' => 'datetime',
        'escalated_to' => 'integer',
        'emergency_actions' => 'array',
        'reported_by' => 'integer',
        'acknowledged_at' => 'datetime',
        'acknowledged_by' => 'integer',
        'assigned_to' => 'integer',
        'resolved_at' => 'datetime',
        'resolved_by' => 'integer',
        'parent_notified' => 'boolean',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'closed_at' => 'datetime',
        'closed_by' => 'integer',
        'offline_sync' => 'boolean',
        'meta' => 'array',
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
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'route_id');
    }
    public function actions(): HasMany
    {
        return $this->hasMany(IncidentAction::class, 'incident_id');
    }
    public function attachments(): HasMany
    {
        return $this->hasMany(IncidentAttachment::class, 'incident_id');
    }
}
