<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Template course (§47): kerangka section yang dapat dipakai ulang guru.
 */
class LmsCourseTemplate extends BaseModel
{
    use LogsLmsActivity, SoftDeletes;

    protected $table = 'lms_course_templates';

    protected $casts = [
        'payload' => 'array',
        'sections_count' => 'integer',
        'items_count' => 'integer',
        'is_system' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(LmsCategory::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_COURSE_TEMPLATE';
    }
}
