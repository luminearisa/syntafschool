<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\RubricAssessmentStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penilaian rubric satu siswa (§24) — total dihitung sistem, bukan diketik
 * manual, sehingga nilai selalu konsisten dengan level yang dipilih.
 */
class LmsRubricAssessment extends BaseModel
{
    protected $table = 'lms_rubric_assessments';

    protected $casts = [
        'status' => RubricAssessmentStatus::class,
        'total_score' => 'decimal:2',
        'maximum_score' => 'decimal:2',
        'normalized_score' => 'decimal:4',
        'feedback_meta' => 'array',
        'assessed_at' => 'datetime',
    ];

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(LmsRubric::class, 'rubric_id');
    }

    public function gradeItem(): BelongsTo
    {
        return $this->belongsTo(LmsGradeItem::class, 'grade_item_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(LmsRubricAssessmentScore::class, 'rubric_assessment_id');
    }

    public function scoreFor(int $criterionId): ?LmsRubricAssessmentScore
    {
        return $this->scores()->where('criterion_id', $criterionId)->first();
    }
}
