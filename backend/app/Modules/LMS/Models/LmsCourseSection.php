<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\ContentVisibility;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Section/pertemuan di dalam course (§11). */
class LmsCourseSection extends BaseModel
{
    use LogsLmsActivity, SoftDeletes;

    protected $table = 'lms_course_sections';

    protected $casts = [
        'visibility' => ContentVisibility::class,
        'availability' => 'array',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LmsLearningItem::class, 'section_id')->orderBy('sort_order');
    }

    public function isHidden(): bool
    {
        return $this->visibility === ContentVisibility::HIDDEN;
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_SECTION';
    }
}
