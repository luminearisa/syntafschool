<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardingEvent extends Model
{

    protected $table = 'boarding_events';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'trip_passenger_id' => 'integer',
        'student_id' => 'integer',
        'employee_id' => 'integer',
        'stop_id' => 'integer',
        'occurred_at' => 'datetime',
        'latitude' => 'decimal:2',
        'longitude' => 'decimal:2',
        'offline_sync' => 'boolean',
        'recorded_by' => 'integer',
        'payload' => 'array',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
    public function passenger(): BelongsTo
    {
        return $this->belongsTo(TripPassenger::class, 'trip_passenger_id');
    }
}
