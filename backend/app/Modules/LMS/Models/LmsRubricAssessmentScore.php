<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Skor satu kriteria pada penilaian rubric (§24). */
class LmsRubricAssessmentScore extends BaseModel
{
    protected $table = 'lms_rubric_assessment_scores';

    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(LmsRubricAssessment::class, 'rubric_assessment_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(LmsRubricCriterion::class, 'criterion_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(LmsRubricLevel::class, 'level_id');
    }
}
