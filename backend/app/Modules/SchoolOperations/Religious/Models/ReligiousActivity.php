<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `religious_activities`.
 */
class ReligiousActivity extends Model
{
    protected $table = 'religious_activities';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'campus_id' => 'integer',
        'organization_id' => 'integer',
        'area_id' => 'integer',
        'instructor_personnel_id' => 'integer',
        'target_filter' => 'array',
        'capacity' => 'integer',
        'waitlist_enabled' => 'boolean',
        'requires_approval' => 'boolean',
        'is_mandatory' => 'boolean',
        'recurring' => 'boolean',
        'recurrence_rule' => 'array',
        'assessment_enabled' => 'boolean',
        'certificate_enabled' => 'boolean',
        'lms_course_id' => 'integer',
        'linked_activity_id' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(MosqueArea::class, 'area_id');
    }

    public function mosqueUtilizationLogs(): HasMany
    {
        return $this->hasMany(MosqueUtilizationLog::class, 'activity_id');
    }

    public function religiousActivitySessions(): HasMany
    {
        return $this->hasMany(ReligiousActivitySession::class, 'activity_id');
    }

    public function religiousActivityRegistrations(): HasMany
    {
        return $this->hasMany(ReligiousActivityRegistration::class, 'activity_id');
    }

    public function religiousActivityParticipants(): HasMany
    {
        return $this->hasMany(ReligiousActivityParticipant::class, 'activity_id');
    }

    public function religiousActivityAttendance(): HasMany
    {
        return $this->hasMany(ReligiousActivityAttendance::class, 'activity_id');
    }

    public function religiousStudySessions(): HasMany
    {
        return $this->hasMany(ReligiousStudySession::class, 'activity_id');
    }

    public function religiousActivityAssessments(): HasMany
    {
        return $this->hasMany(ReligiousActivityAssessment::class, 'activity_id');
    }

    public function religiousAchievements(): HasMany
    {
        return $this->hasMany(ReligiousAchievement::class, 'activity_id');
    }
}
