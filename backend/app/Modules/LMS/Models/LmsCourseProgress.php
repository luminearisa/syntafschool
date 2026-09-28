<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ringkasan progress satu peserta di satu course (§23, §42). */
class LmsCourseProgress extends BaseModel
{
    protected $table = 'lms_course_progress';

    protected $casts = [
        'progress_percent' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'computed_at' => 'datetime',
        'total_items' => 'integer',
        'required_items' => 'integer',
        'completed_items' => 'integer',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function lastItem(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'last_item_id');
    }

    public function nextItem(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'next_item_id');
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
