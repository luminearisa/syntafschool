<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\InterventionType;
use App\Modules\Bk\Domain\Enums\InterventionStatus;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Intervensi yang dilaksanakan (konseling, pendampingan, koordinasi guru,
penyesuaian lingkungan, rujukan, dsb) beserta hasilnya.
 *
 * Tabel: `bk_interventions`
 */
class BkIntervention extends BkModel
{
    protected $table = 'bk_interventions';

    use SoftDeletes;

    protected $casts = [
        'start_date' => 'date',
        'target_date' => 'date',
        'completed_at' => 'datetime',
        'follow_up_required' => 'boolean',
        'type' => \App\Modules\Bk\Domain\Enums\InterventionType::class,
        'status' => \App\Modules\Bk\Domain\Enums\InterventionStatus::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BkCounselingPlan::class, 'plan_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(BkGoal::class, 'goal_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(BkFollowUp::class, 'intervention_id');
    }

    /** Intervensi yang masih berjalan (dasar widget "intervensi terbuka"). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['PLANNED', 'IN_PROGRESS']);
    }
}
