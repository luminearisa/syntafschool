<?php

namespace App\Modules\Bk\Models;

use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sasaran pengembangan.
 *
 * Baseline, target, dan cara mengukur bukan pelengkap: tanpa ketiganya, kata
 * kemajuan hanya menjadi klaim yang tidak dapat diperiksa.
 *
 * Tabel: `bk_development_goals`
 */
class BkDevelopmentGoal extends BkModel
{
    protected $table = 'bk_development_goals';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'baseline_value' => 'decimal:4',
        'target_value' => 'decimal:4',
        'current_value' => 'decimal:4',
        'start_date' => 'date',
        'target_date' => 'date',
        'achieved_at' => 'datetime',
        'status' => \App\Modules\Bk\Domain\Enums\DevelopmentGoalStatus::class,
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BkDevelopmentPlan::class, 'plan_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(BkDevelopmentActivity::class, 'goal_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(BkDevelopmentReview::class, 'goal_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['NOT_STARTED', 'IN_PROGRESS', 'ON_TRACK', 'AT_RISK']);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('target_date')->whereDate('target_date', '<', today());
    }
}
