<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentTransportProfile extends Model
{

    protected $table = 'student_transport_profiles';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'student_id' => 'integer',
        'campus_id' => 'integer',
        'transport_required' => 'boolean',
        'service_id' => 'integer',
        'pickup_stop_id' => 'integer',
        'dropoff_stop_id' => 'integer',
        'morning_route_id' => 'integer',
        'afternoon_route_id' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'escort_required' => 'boolean',
        'wheelchair_required' => 'boolean',
        'authorized_pickup_restricted' => 'boolean',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(TransportationService::class, 'service_id');
    }
    public function pickupStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'pickup_stop_id');
    }
    public function dropoffStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'dropoff_stop_id');
    }
    public function morningRoute(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'morning_route_id');
    }
    public function afternoonRoute(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'afternoon_route_id');
    }
    public function assignments(): HasMany
    {
        return $this->hasMany(TransportationAssignment::class, 'student_id');
    }
    public function requests(): HasMany
    {
        return $this->hasMany(TransportationRequest::class, 'student_id');
    }
    public function pickupPersons(): HasMany
    {
        return $this->hasMany(AuthorizedPickupPerson::class, 'student_id');
    }
}
