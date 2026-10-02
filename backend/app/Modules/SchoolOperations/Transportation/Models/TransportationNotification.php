<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportationNotification extends Model
{

    protected $table = 'transportation_notifications';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'recipient_user_id' => 'integer',
        'student_id' => 'integer',
        'trip_id' => 'integer',
        'incident_id' => 'integer',
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
    public function incident(): BelongsTo
    {
        return $this->belongsTo(TransportationIncident::class, 'incident_id');
    }
}
