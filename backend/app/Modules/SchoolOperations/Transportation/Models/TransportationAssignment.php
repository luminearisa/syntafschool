<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportationAssignment extends Model
{

    protected $table = 'transportation_assignments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'student_id' => 'integer',
        'request_id' => 'integer',
        'service_id' => 'integer',
        'campus_id' => 'integer',
        'route_id' => 'integer',
        'morning_route_id' => 'integer',
        'afternoon_route_id' => 'integer',
        'pickup_stop_id' => 'integer',
        'dropoff_stop_id' => 'integer',
        'vehicle_id' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'billable' => 'boolean',
        'fee_amount' => 'decimal:2',
        'assigned_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'suspended_at' => 'datetime',
        'meta' => 'array',
    ];

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentTransportProfile::class, 'student_id');
    }
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'route_id');
    }
    public function service(): BelongsTo
    {
        return $this->belongsTo(TransportationService::class, 'service_id');
    }
    public function morningRoute(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'morning_route_id');
    }
    public function afternoonRoute(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'afternoon_route_id');
    }
    public function pickupStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'pickup_stop_id');
    }
    public function dropoffStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'dropoff_stop_id');
    }
    public function request(): BelongsTo
    {
        return $this->belongsTo(TransportationRequest::class, 'request_id');
    }
}
