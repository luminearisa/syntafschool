<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\PlanStatus;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rencana konseling: masalah → sasaran → intervensi → indikator → tinjauan.
 *
 * Tabel: `bk_counseling_plans`
 */
class BkCounselingPlan extends BkModel
{
    protected $table = 'bk_counseling_plans';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'started_on' => 'date',
        'target_end_on' => 'date',
        'reviewed_at' => 'datetime',
        'status' => \App\Modules\Bk\Domain\Enums\PlanStatus::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(BkGoal::class, 'plan_id');
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(BkIntervention::class, 'plan_id');
    }
}
