<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\GoalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Sasaran pada rencana konseling beserta indikator keberhasilan.
 *
 * Tabel: `bk_goals`
 */
class BkGoal extends BkModel
{
    protected $table = 'bk_goals';

    protected $casts = [
        'timeline_start' => 'date',
        'timeline_end' => 'date',
        'reviewed_at' => 'datetime',
        'status' => \App\Modules\Bk\Domain\Enums\GoalStatus::class,
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BkCounselingPlan::class, 'plan_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(BkIntervention::class, 'goal_id');
    }
}
