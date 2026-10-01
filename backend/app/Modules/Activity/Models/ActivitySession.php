<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\SessionStatus;

/**
 * Pertemuan nyata — tempat kehadiran, materi, dan penilaian menempel.
 *
 * Tabel: `activity_sessions`
 */
class ActivitySession extends ActivityModel
{
    protected $table = 'activity_sessions';

    protected $casts = [
        'date' => 'date',
        'session_number' => 'integer',
        'status' => SessionStatus::class,
        'attendance_locked_at' => 'datetime',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function period(): BelongsTo
    {
        return $this->belongsTo(ActivityPeriod::class, 'period_id');
    }
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ActivitySchedule::class, 'schedule_id');
    }
    public function room(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Room::class, 'room_id');
    }
    public function coach(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'coach_employee_id');
    }
    public function materials(): HasMany
    {
        return $this->hasMany(ActivitySessionMaterial::class, 'session_id');
    }
    public function attendance(): HasMany
    {
        return $this->hasMany(ActivityAttendance::class, 'session_id');
    }
    public function assessments(): HasMany
    {
        return $this->hasMany(ActivityAssessment::class, 'session_id');
    }
    public function facilityBookings(): HasMany
    {
        return $this->hasMany(ActivityFacilityBooking::class, 'session_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
