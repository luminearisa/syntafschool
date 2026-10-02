<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripStopEvent extends Model
{

    protected $table = 'trip_stop_events';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'trip_stop_id' => 'integer',
        'student_id' => 'integer',
        'driver_profile_id' => 'integer',
        'occurred_at' => 'datetime',
        'latitude' => 'decimal:2',
        'longitude' => 'decimal:2',
        'payload' => 'array',
        'recorded_by' => 'integer',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
    public function stop(): BelongsTo
    {
        return $this->belongsTo(TripStop::class, 'trip_stop_id');
    }
}
