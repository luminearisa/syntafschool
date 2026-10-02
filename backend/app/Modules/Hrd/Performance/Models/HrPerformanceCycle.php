<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Performance\Models\HrPerformanceCycleParticipant;
use App\Modules\Hrd\Performance\Models\HrPerformanceGoal;
use App\Modules\Hrd\Performance\Models\HrPerformanceReview;

/**
 * Phase 2 — tabel `hr_performance_cycles` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPerformanceCycle extends HrdModel
{
    protected $table = 'hr_performance_cycles';

    protected $guarded = [];
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'goal_setting_start' => 'date:Y-m-d',
        'goal_setting_end' => 'date:Y-m-d',
        'mid_review_at' => 'date:Y-m-d',
        'review_start' => 'date:Y-m-d',
        'review_end' => 'date:Y-m-d',
        'calibration_at' => 'date:Y-m-d',
        'include_competency' => 'boolean',
        'include_goals' => 'boolean',
        'weight_goals' => 'decimal:2',
        'weight_competency' => 'decimal:2',
        'meta' => 'array',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(HrPerformanceCycleParticipant::class, 'cycle_id');
    }
    public function goals(): HasMany
    {
        return $this->hasMany(HrPerformanceGoal::class, 'cycle_id');
    }
    public function reviews(): HasMany
    {
        return $this->hasMany(HrPerformanceReview::class, 'cycle_id');
    }
}
