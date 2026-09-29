<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\DripReleaseType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aturan pelepasan konten bertahap (§4).
 */
class LmsDripRule extends BaseModel
{
    protected $table = 'lms_drip_rules';

    protected $casts = [
        'release_type' => DripReleaseType::class,
        'release_at' => 'datetime',
        'days_after_enrollment' => 'integer',
        'days_after_previous_completion' => 'integer',
        'is_active' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(LmsCourseSection::class, 'section_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'item_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function afterItem(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'after_item_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
