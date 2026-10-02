<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_activity_attendance`.
 */
class ReligiousActivityAttendance extends Model
{
    protected $table = 'religious_activity_attendance';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'participant_id' => 'integer',
        'activity_id' => 'integer',
        'session_id' => 'integer',
        'attendance_date' => 'date',
        'recorded_by' => 'integer',
        'recorded_at' => 'datetime',
        'minutes_late' => 'integer',
        'meta' => 'array',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivityParticipant::class, 'participant_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivity::class, 'activity_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivitySession::class, 'session_id');
    }
}
