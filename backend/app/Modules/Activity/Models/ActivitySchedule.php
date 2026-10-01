<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\SessionType;

/**
 * Pola jadwal berulang (mis. setiap Senin 15:00–17:00) yang menjadi sumber sesi nyata.
 *
 * Tabel: `activity_schedules`
 */
class ActivitySchedule extends ActivityModel
{
    protected $table = 'activity_schedules';

    protected $casts = [
        'day_of_week' => 'integer',
        'session_type' => SessionType::class,
        'is_recurring' => 'boolean',
        'effective_start' => 'date',
        'effective_end' => 'date',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function period(): BelongsTo
    {
        return $this->belongsTo(ActivityPeriod::class, 'period_id');
    }
    public function room(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Room::class, 'room_id');
    }
    public function coach(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'coach_employee_id');
    }
    public function sessions(): HasMany
    {
        return $this->hasMany(ActivitySession::class, 'schedule_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
