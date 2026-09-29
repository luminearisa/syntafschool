<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Models\Employee;
use App\Modules\Supervisi\Domain\Enums\ActivityKind;
use App\Modules\Supervisi\Domain\Enums\ScheduleStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Agenda/undangan supervisi. */
class SupSchedule extends BaseModel
{
    use SoftDeletes;

    protected $table = 'sup_schedules';

    protected $casts = [
        'activity_kind' => ActivityKind::class,
        'status' => ScheduleStatus::class,
        'planned_at' => 'datetime',
        'window_end' => 'datetime',
        'duration_minutes' => 'integer',
        'invited_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(SupProgram::class, 'program_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SupAssignment::class, 'assignment_id');
    }

    public function instrumentVersion(): BelongsTo
    {
        return $this->belongsTo(SupInstrumentVersion::class, 'instrument_version_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id');
    }

    public function supervisee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisee_employee_id');
    }
}
