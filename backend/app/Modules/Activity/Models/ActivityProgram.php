<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Activity\Domain\Enums\GenderPolicy;
use App\Modules\Activity\Domain\Enums\ProgramDeliveryType;
use App\Modules\Activity\Domain\Enums\ProgramStatus;
use App\Modules\Activity\Domain\Enums\ProgramType;
use App\Modules\Activity\Domain\Enums\SelectionMethod;

/**
 * Program aktivitas — entitas "aktivitas" yang sesungguhnya (Futsal SMP, Robotics Club, OSIS, Tahfidz).
 *
 * Tabel: `activity_programs`
 */
class ActivityProgram extends ActivityModel
{
    protected $table = 'activity_programs';

    use SoftDeletes;

    protected $casts = [
        'type' => ProgramType::class,
        'delivery_type' => ProgramDeliveryType::class,
        'status' => ProgramStatus::class,
        'gender' => GenderPolicy::class,
        'selection_method' => SelectionMethod::class,
        'fee_amount' => 'decimal:2',
        'requires_parent_consent' => 'boolean',
        'requires_payment' => 'boolean',
        'registration_opens' => 'date',
        'registration_closes' => 'date',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'completion_rules' => 'array',
        'certificate_rules' => 'array',
        'metadata' => 'array',
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
        'minimum_capacity' => 'integer',
        'maximum_capacity' => 'integer',
        'minimum_attendance_percentage' => 'integer',
    ];
    public function category(): BelongsTo
    {
        return $this->belongsTo(ActivityCategory::class, 'category_id');
    }
    public function period(): BelongsTo
    {
        return $this->belongsTo(ActivityPeriod::class, 'period_id');
    }
    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'coordinator_user_id');
    }
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'advisor_employee_id');
    }
    public function coach(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'coach_employee_id');
    }
    public function room(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Room::class, 'room_id');
    }
    public function lmsCourse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LmsCourse::class, 'lms_course_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(ActivitySkill::class, 'activity_program_skills', 'program_id', 'skill_id')->withPivot('is_required', 'weight')->withTimestamps();
    }
    public function objectives(): BelongsToMany
    {
        return $this->belongsToMany(ActivityObjective::class, 'activity_program_objectives', 'program_id', 'objective_id')->withPivot('target_level')->withTimestamps();
    }
    public function requirements(): HasMany
    {
        return $this->hasMany(ActivityRegistrationRequirement::class, 'program_id');
    }
    public function coaches(): HasMany
    {
        return $this->hasMany(ActivityProgramCoach::class, 'program_id');
    }
    public function registrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class, 'program_id');
    }
    public function memberships(): HasMany
    {
        return $this->hasMany(ActivityMembership::class, 'program_id');
    }
    public function schedules(): HasMany
    {
        return $this->hasMany(ActivitySchedule::class, 'program_id');
    }
    public function sessions(): HasMany
    {
        return $this->hasMany(ActivitySession::class, 'program_id');
    }
    public function assessments(): HasMany
    {
        return $this->hasMany(ActivityAssessment::class, 'program_id');
    }
    public function projects(): HasMany
    {
        return $this->hasMany(ActivityProject::class, 'program_id');
    }
    public function announcements(): HasMany
    {
        return $this->hasMany(ActivityAnnouncement::class, 'program_id');
    }
    public function achievements(): HasMany
    {
        return $this->hasMany(ActivityAchievement::class, 'program_id');
    }
    public function charges(): HasMany
    {
        return $this->hasMany(ActivityCharge::class, 'program_id');
    }
}
