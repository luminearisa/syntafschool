<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleException extends Model
{

    protected $table = 'schedule_exceptions';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'schedule_id' => 'integer',
        'route_id' => 'integer',
        'exception_date' => 'date',
        'cancel_trips' => 'boolean',
        'created_by' => 'integer',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TransportationSchedule::class, 'schedule_id');
    }
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'route_id');
    }
}
