<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `religious_activity_sessions`.
 */
class ReligiousActivitySession extends Model
{
    protected $table = 'religious_activity_sessions';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'activity_id' => 'integer',
        'session_number' => 'integer',
        'session_date' => 'date',
        'area_id' => 'integer',
        'instructor_personnel_id' => 'integer',
        'mosque_id' => 'integer',
        'capacity' => 'integer',
        'registered_count' => 'integer',
        'attended_count' => 'integer',
        'attendance_opened_at' => 'datetime',
        'attendance_closed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivity::class, 'activity_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(MosqueArea::class, 'area_id');
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function religiousActivityRegistrations(): HasMany
    {
        return $this->hasMany(ReligiousActivityRegistration::class, 'session_id');
    }

    public function religiousActivityParticipants(): HasMany
    {
        return $this->hasMany(ReligiousActivityParticipant::class, 'session_id');
    }

    public function religiousActivityAttendance(): HasMany
    {
        return $this->hasMany(ReligiousActivityAttendance::class, 'session_id');
    }

    public function religiousStudySessions(): HasMany
    {
        return $this->hasMany(ReligiousStudySession::class, 'session_id');
    }

    public function religiousActivityAssessments(): HasMany
    {
        return $this->hasMany(ReligiousActivityAssessment::class, 'session_id');
    }
}
