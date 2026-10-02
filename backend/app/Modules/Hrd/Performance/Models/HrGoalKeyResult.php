<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Performance\Models\HrGoalUpdate;
use App\Modules\Hrd\Performance\Models\HrPerformanceGoal;

/**
 * Phase 2 — tabel `hr_goal_key_results` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrGoalKeyResult extends HrdModel
{
    protected $table = 'hr_goal_key_results';

    protected $guarded = [];
    protected $casts = [
        'target_value' => 'decimal:2',
        'current_value' => 'decimal:2',
        'weight' => 'decimal:2',
        'progress' => 'decimal:2',
        'due_date' => 'date:Y-m-d',
        'meta' => 'array',
    ];

    public function goal(): BelongsTo
    {
        return $this->belongsTo(HrPerformanceGoal::class, 'goal_id');
    }
    public function updates(): HasMany
    {
        return $this->hasMany(HrGoalUpdate::class, 'key_result_id');
    }
}
