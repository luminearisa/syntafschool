<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `religious_activity_participants`.
 */
class ReligiousActivityParticipant extends Model
{
    protected $table = 'religious_activity_participants';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'activity_id' => 'integer',
        'session_id' => 'integer',
        'registration_id' => 'integer',
        'student_id' => 'integer',
        'employee_id' => 'integer',
        'organization_id' => 'integer',
        'dormitory_id' => 'integer',
        'joined_at' => 'datetime',
        'completed_at' => 'datetime',
        'assessment_score' => 'decimal:2',
        'meta' => 'array',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivity::class, 'activity_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivitySession::class, 'session_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivityRegistration::class, 'registration_id');
    }

    public function religiousActivityAttendance(): HasMany
    {
        return $this->hasMany(ReligiousActivityAttendance::class, 'participant_id');
    }

    public function religiousActivityAssessments(): HasMany
    {
        return $this->hasMany(ReligiousActivityAssessment::class, 'participant_id');
    }

    public function religiousAchievements(): HasMany
    {
        return $this->hasMany(ReligiousAchievement::class, 'participant_id');
    }

    public function religiousCertificates(): HasMany
    {
        return $this->hasMany(ReligiousCertificate::class, 'participant_id');
    }
}
