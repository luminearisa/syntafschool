<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Performance\Models\HrPerformanceCycle;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Performance\Models\HrGoalKeyResult;
use App\Modules\Hrd\Performance\Models\HrGoalUpdate;

/**
 * Phase 2 — tabel `hr_performance_goals` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPerformanceGoal extends HrdModel
{
    protected $table = 'hr_performance_goals';

    protected $guarded = [];
    protected $casts = [
        'target_value' => 'decimal:2',
        'weight' => 'decimal:2',
        'start_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'progress' => 'decimal:2',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function keyResults(): HasMany
    {
        return $this->hasMany(HrGoalKeyResult::class, 'goal_id');
    }
    public function updates(): HasMany
    {
        return $this->hasMany(HrGoalUpdate::class, 'goal_id');
    }
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(HrPerformanceCycle::class, 'cycle_id');
    }
}
