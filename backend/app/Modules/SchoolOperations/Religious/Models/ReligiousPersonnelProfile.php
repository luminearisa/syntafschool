<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `religious_personnel_profiles`.
 */
class ReligiousPersonnelProfile extends Model
{
    protected $table = 'religious_personnel_profiles';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'employee_id' => 'integer',
        'student_id' => 'integer',
        'user_id' => 'integer',
        'languages' => 'array',
        'verified_at' => 'datetime',
        'verified_by' => 'integer',
        'active' => 'boolean',
        'meta' => 'array',
    ];

    public function imamProfiles(): HasMany
    {
        return $this->hasMany(ImamProfile::class, 'personnel_id');
    }

    public function muadzinProfiles(): HasMany
    {
        return $this->hasMany(MuadzinProfile::class, 'personnel_id');
    }

    public function khatibProfiles(): HasMany
    {
        return $this->hasMany(KhatibProfile::class, 'personnel_id');
    }

    public function religiousPersonnelAvailability(): HasMany
    {
        return $this->hasMany(ReligiousPersonnelAvailability::class, 'personnel_id');
    }

    public function religiousTeacherProfiles(): HasMany
    {
        return $this->hasMany(ReligiousTeacherProfile::class, 'personnel_id');
    }

    public function religiousDutyAssignments(): HasMany
    {
        return $this->hasMany(ReligiousDutyAssignment::class, 'personnel_id');
    }

    public function religiousSubstitutions(): HasMany
    {
        return $this->hasMany(ReligiousSubstitution::class, 'original_personnel_id');
    }

    public function religiousStudySessions(): HasMany
    {
        return $this->hasMany(ReligiousStudySession::class, 'personnel_id');
    }

    public function fridayPrayerAssignments(): HasMany
    {
        return $this->hasMany(FridayPrayerAssignment::class, 'personnel_id');
    }
}
