<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\RubricGradingMethod;
use App\Modules\LMS\Domain\Enums\RubricStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rubric penilaian (§22–§25).
 *
 * `course_id` null berarti template institusi yang dapat dipakai ulang guru
 * mana pun; rubric milik course hanya untuk course tersebut.
 */
class LmsRubric extends BaseModel
{
    protected $table = 'lms_rubrics';

    protected $casts = [
        'grading_method' => RubricGradingMethod::class,
        'status' => RubricStatus::class,
        'maximum_score' => 'decimal:2',
        'is_template' => 'boolean',
        'is_shared' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(LmsRubricCriterion::class, 'rubric_id')->orderBy('sort_order');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(LmsRubricAssessment::class, 'rubric_id');
    }

    /** Total skor maksimum teoretis (mengikuti bobot kriteria). */
    public function computedMaximum(): float
    {
        $this->loadMissing('criteria.levels');

        return round((float) $this->criteria->sum(
            fn (LmsRubricCriterion $criterion) => $criterion->maximumScore() * max((float) $criterion->weight, 0.0)
        ), 2);
    }

    /** @return array<string, mixed> */
    public function toStructure(): array
    {
        $this->loadMissing('criteria.levels');

        return [
            'id' => (int) $this->id,
            'course_id' => $this->course_id === null ? null : (int) $this->course_id,
            'name' => $this->name,
            'description' => $this->description,
            'grading_method' => $this->grading_method->value,
            'grading_method_label' => $this->grading_method->label(),
            'maximum_score' => (float) $this->maximum_score,
            'computed_maximum' => $this->computedMaximum(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'is_template' => (bool) $this->is_template,
            'is_shared' => (bool) $this->is_shared,
            'criteria' => $this->criteria->map(fn (LmsRubricCriterion $criterion) => $criterion->toStructure())->all(),
        ];
    }
}
