<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu item tuntas bagi satu peserta (§22). */
class LmsActivityCompletion extends BaseModel
{
    use LogsLmsActivity;

    protected $table = 'lms_activity_completions';

    protected $casts = [
        'completion_type' => CompletionType::class,
        'grade_value' => 'decimal:2',
        'passed' => 'boolean',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'learning_item_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function scopeComplete(Builder $query): Builder
    {
        return $query->where('status', 'COMPLETE');
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_COMPLETION';
    }
}
