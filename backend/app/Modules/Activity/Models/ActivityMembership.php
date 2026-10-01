<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Activity\Domain\Enums\MembershipRole;
use App\Modules\Activity\Domain\Enums\MembershipStatus;

/**
 * Keanggotaan siswa pada program untuk satu periode. Riwayat tidak pernah ditimpa.
 *
 * Tabel: `activity_memberships`
 */
class ActivityMembership extends ActivityModel
{
    protected $table = 'activity_memberships';

    use SoftDeletes;

    protected $casts = [
        'status' => MembershipStatus::class,
        'role' => MembershipRole::class,
        'joined_at' => 'date',
        'ended_at' => 'date',
        'suspended_at' => 'datetime',
        'leadership_eligible' => 'boolean',
        'metadata' => 'array',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function period(): BelongsTo
    {
        return $this->belongsTo(ActivityPeriod::class, 'period_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ActivityRegistration::class, 'registration_id');
    }
    public function histories(): HasMany
    {
        return $this->hasMany(ActivityMembershipHistory::class, 'membership_id')->orderBy('id');
    }
    public function progress(): HasOne
    {
        return $this->hasOne(ActivityProgress::class, 'membership_id');
    }
    public function completions(): HasMany
    {
        return $this->hasMany(ActivityCompletion::class, 'membership_id');
    }
    public function attendance(): HasMany
    {
        return $this->hasMany(ActivityAttendance::class, 'membership_id');
    }
    public function assessmentResults(): HasMany
    {
        return $this->hasMany(ActivityAssessmentResult::class, 'membership_id');
    }
}
