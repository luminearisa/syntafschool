<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Models\Employee;
use App\Modules\Supervisi\Domain\Enums\AssignmentRole;
use App\Modules\Supervisi\Domain\Enums\SupervisionApproach;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penugasan supervisor ↔ yang disupervisi di dalam satu program. */
class SupAssignment extends BaseModel
{
    protected $table = 'sup_assignments';

    protected $casts = [
        'role' => AssignmentRole::class,
        'approach' => SupervisionApproach::class,
        'allowed_instruments' => 'array',
        'min_visits' => 'integer',
        'target_visits' => 'integer',
        'is_active' => 'boolean',
        'active_from' => 'date',
        'active_to' => 'date',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(SupProgram::class, 'program_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id');
    }

    public function supervisee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisee_employee_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
