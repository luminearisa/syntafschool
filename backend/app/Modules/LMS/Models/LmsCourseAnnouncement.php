<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pengumuman dalam course (§24). */
class LmsCourseAnnouncement extends BaseModel
{
    use LogsLmsActivity, SoftDeletes;

    protected $table = 'lms_course_announcements';

    protected $casts = [
        'pinned' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('pinned')->orderByDesc('published_at');
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_ANNOUNCEMENT';
    }
}
