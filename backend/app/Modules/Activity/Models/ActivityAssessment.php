<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\AssessmentStatus;
use App\Modules\Activity\Domain\Enums\AssessmentType;

/**
 * Penilaian aktivitas (keterampilan, performa, partisipasi, proyek, perilaku).
 *
 * Tabel: `activity_assessments`
 */
class ActivityAssessment extends ActivityModel
{
    protected $table = 'activity_assessments';

    protected $casts = [
        'type' => AssessmentType::class,
        'status' => AssessmentStatus::class,
        'max_score' => 'decimal:2',
        'weight' => 'decimal:2',
        'is_final' => 'boolean',
        'counts_toward_completion' => 'boolean',
        'scheduled_date' => 'date',
        'due_date' => 'date',
        'published_at' => 'datetime',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function period(): BelongsTo
    {
        return $this->belongsTo(ActivityPeriod::class, 'period_id');
    }
    public function session(): BelongsTo
    {
        return $this->belongsTo(ActivitySession::class, 'session_id');
    }
    public function rubric(): BelongsTo
    {
        return $this->belongsTo(ActivityRubric::class, 'rubric_id');
    }
    public function results(): HasMany
    {
        return $this->hasMany(ActivityAssessmentResult::class, 'assessment_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
