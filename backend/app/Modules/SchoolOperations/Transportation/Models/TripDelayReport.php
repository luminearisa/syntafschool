<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripDelayReport extends Model
{

    protected $table = 'trip_delay_reports';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'delay_minutes' => 'integer',
        'reported_at' => 'datetime',
        'reported_by' => 'integer',
        'trip_stop_id' => 'integer',
        'notification_sent' => 'boolean',
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
