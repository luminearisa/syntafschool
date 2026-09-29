<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\GradeReviewStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Banding nilai siswa (§20, §59).
 */
class LmsGradeReview extends BaseModel
{
    protected $table = 'lms_grade_reviews';

    protected $casts = [
        'status' => GradeReviewStatus::class,
        'current_score' => 'decimal:2',
        'requested_score' => 'decimal:2',
        'decided_score' => 'decimal:2',
        'is_parent_visible' => 'boolean',
        'decided_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(LmsGradebook::class, 'gradebook_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LmsGradeItem::class, 'grade_item_id');
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(LmsAssessmentResult::class, 'assessment_result_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LmsGradeReviewEvent::class, 'grade_review_id')->orderBy('created_at');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
