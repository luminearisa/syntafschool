<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Models\Employee;
use App\Modules\Supervisi\Domain\Enums\ActivityKind;
use App\Modules\Supervisi\Domain\Enums\SupervisionApproach;
use App\Modules\Supervisi\Domain\Enums\VisitStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu kegiatan supervisi (walkthrough, observasi, klinis, tendik, …).
 *
 * Nilai akhir (`raw_score` … `grade_tone`) **selalu** dihitung server melalui
 * `ScoringEngine`; frontend tidak pernah mengirim angka akhir.
 */
class SupVisit extends BaseModel
{
    use SoftDeletes;

    protected $table = 'sup_visits';

    protected $casts = [
        'activity_kind' => ActivityKind::class,
        'approach' => SupervisionApproach::class,
        'status' => VisitStatus::class,
        'observed_at' => 'datetime',
        'pre_conference_at' => 'datetime',
        'post_conference_at' => 'datetime',
        'scored_at' => 'datetime',
        'finalized_at' => 'datetime',
        'raw_score' => 'float',
        'max_score' => 'float',
        'final_score' => 'float',
        'snapshot' => 'array',
        'is_disputed' => 'boolean',
        'revision' => 'integer',
    ];

    public function answers(): HasMany
    {
        return $this->hasMany(SupVisitAnswer::class, 'visit_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(SupVisitScore::class, 'visit_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(SupVisitEvidence::class, 'visit_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(SupVisitNote::class, 'visit_id');
    }

    public function conferences(): HasMany
    {
        return $this->hasMany(SupConference::class, 'visit_id');
    }

    public function preConference(): HasOne
    {
        return $this->hasOne(SupConference::class, 'visit_id')->where('kind', 'PRE');
    }

    public function postConference(): HasOne
    {
        return $this->hasOne(SupConference::class, 'visit_id')->where('kind', 'POST');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(SupFinding::class, 'visit_id');
    }

    public function acknowledgments(): HasMany
    {
        return $this->hasMany(SupAcknowledgment::class, 'visit_id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(SupDispute::class, 'visit_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SupVisitRevision::class, 'visit_id')->orderByDesc('revision');
    }

    public function actionPlans(): HasMany
    {
        return $this->hasMany(SupActionPlan::class, 'visit_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(SupProgram::class, 'program_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(SupSchedule::class, 'schedule_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SupAssignment::class, 'assignment_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id');
    }

    public function supervisee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisee_employee_id');
    }

    public function instrumentVersion(): BelongsTo
    {
        return $this->belongsTo(SupInstrumentVersion::class, 'instrument_version_id');
    }

    public function scopeForSupervisee(Builder $query, int $employeeId): Builder
    {
        return $query->where('supervisee_employee_id', $employeeId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            VisitStatus::COMPLETED->value,
            VisitStatus::EVALUATED->value,
            VisitStatus::CANCELLED->value,
        ]);
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    /** Kegiatan masih dapat diisi (belum final & belum dibatalkan). */
    public function isEditable(): bool
    {
        return ! $this->isFinalized() && $this->status !== VisitStatus::CANCELLED;
    }
}
