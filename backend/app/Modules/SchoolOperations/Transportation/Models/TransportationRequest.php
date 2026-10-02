<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportationRequest extends Model
{

    protected $table = 'transportation_requests';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'student_id' => 'integer',
        'guardian_id' => 'integer',
        'requested_by' => 'integer',
        'service_id' => 'integer',
        'campus_id' => 'integer',
        'route_id' => 'integer',
        'morning_route_id' => 'integer',
        'afternoon_route_id' => 'integer',
        'pickup_stop_id' => 'integer',
        'dropoff_stop_id' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'validation' => 'array',
        'capacity_check' => 'array',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'created_assignment_id' => 'integer',
    ];

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentTransportProfile::class, 'student_id');
    }
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'route_id');
    }
    public function pickupStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'pickup_stop_id');
    }
    public function dropoffStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'dropoff_stop_id');
    }
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TransportationAssignment::class, 'created_assignment_id');
    }
    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(TransportationWaitlist::class, 'request_id');
    }
}
