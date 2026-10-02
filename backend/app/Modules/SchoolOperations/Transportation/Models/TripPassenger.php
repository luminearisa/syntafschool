<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripPassenger extends Model
{

    protected $table = 'trip_passengers';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'manifest_id' => 'integer',
        'student_id' => 'integer',
        'employee_id' => 'integer',
        'assignment_id' => 'integer',
        'pickup_stop_id' => 'integer',
        'dropoff_stop_id' => 'integer',
        'seat_number' => 'integer',
        'boarded_at' => 'datetime',
        'dropped_off_at' => 'datetime',
        'boarded_stop_id' => 'integer',
        'dropped_off_stop_id' => 'integer',
        'authorized_pickup_person_id' => 'integer',
        'special_needs' => 'boolean',
        'meta' => 'array',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(TripManifest::class, 'manifest_id');
    }
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TransportationAssignment::class, 'assignment_id');
    }
    public function pickupStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'pickup_stop_id');
    }
    public function dropoffStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'dropoff_stop_id');
    }
    public function boardingEvent(): BelongsTo
    {
        return $this->belongsTo(BoardingEvent::class, 'id');
    }
    public function dropoffEvent(): BelongsTo
    {
        return $this->belongsTo(DropoffEvent::class, 'id');
    }
    public function authorizedPickupPerson(): BelongsTo
    {
        return $this->belongsTo(AuthorizedPickupPerson::class, 'authorized_pickup_person_id');
    }
}
