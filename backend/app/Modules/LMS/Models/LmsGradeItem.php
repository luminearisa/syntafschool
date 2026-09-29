<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\AggregationMethod;
use App\Modules\LMS\Domain\Enums\GradeItemSource;
use App\Modules\LMS\Domain\Enums\GradeItemVisibility;
use App\Modules\LMS\Domain\Enums\GradingMethod;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grade item (§3) — "sesuatu yang menghasilkan nilai".
 *
 * Bila item berasal dari aktivitas LMS, `activity_id` menunjuk aktivitas itu
 * sehingga jejak nilai dapat ditelusuri sampai submission/attempt (§13).
 */
class LmsGradeItem extends BaseModel
{
    protected $table = 'lms_grade_items';

    protected $casts = [
        'source' => GradeItemSource::class,
        'visibility' => GradeItemVisibility::class,
        'grade_type' => GradingMethod::class,
        'aggregation_method' => AggregationMethod::class,
        'maximum_grade' => 'decimal:2',
        'passing_grade' => 'decimal:2',
        'weight' => 'decimal:2',
        'is_extra_credit' => 'boolean',
        'include_in_final' => 'boolean',
        'is_bonus' => 'boolean',
        'released_at' => 'datetime',
    ];

    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(LmsGradebook::class, 'gradebook_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LmsGradeCategory::class, 'category_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(LmsRubric::class, 'rubric_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(LmsAssessmentResult::class, 'grade_item_id');
    }

    public function resultFor(int $userId): ?LmsAssessmentResult
    {
        return $this->results()->where('user_id', $userId)->first();
    }

    public function isManual(): bool
    {
        return $this->source === GradeItemSource::MANUAL || $this->activity_id === null;
    }

    public function passingGrade(): float
    {
        return (float) ($this->passing_grade
            ?? $this->gradebook?->passing_grade
            ?? $this->maximum_grade * 0.7);
    }

    /** Item ini dihitung dalam nilai akhir? */
    public function countsTowardFinal(): bool
    {
        return $this->include_in_final && $this->visibility !== GradeItemVisibility::HIDDEN;
    }
}
