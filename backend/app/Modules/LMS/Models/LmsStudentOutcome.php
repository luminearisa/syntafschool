<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\OutcomeAchievement;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capaian seorang siswa pada satu learning outcome (§28).
 */
class LmsStudentOutcome extends BaseModel
{
    protected $table = 'lms_student_outcomes';

    protected $casts = [
        'status' => OutcomeAchievement::class,
        'score' => 'decimal:2',
        'evidence_count' => 'integer',
        'calculation' => 'array',
        'assessed_at' => 'datetime',
    ];

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(LmsLearningOutcome::class, 'learning_outcome_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function isAchieved(): bool
    {
        return $this->status->isAchieved();
    }
}
