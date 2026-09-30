<?php

namespace App\Modules\CBT\Models;

use App\Models\Employee;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CbtSession extends CbtModel
{
    use SoftDeletes;

    protected $table = 'cbt_sessions';

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'results_released_at' => 'datetime',
        'settings' => 'array',
        'access_token' => 'string',
    ];

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(CbtBlueprint::class, 'blueprint_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function proctor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'proctor_employee_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CbtParticipant::class, 'session_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CbtAttempt::class, 'session_id');
    }

    public function itemStats(): HasMany
    {
        return $this->hasMany(CbtItemStat::class, 'session_id');
    }
}
