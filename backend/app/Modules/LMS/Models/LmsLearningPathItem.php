<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\DripReleaseType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu modul pada learning path — pembungkus `lms_course_sections` yang sudah
 * ada (§1), bukan struktur konten baru.
 */
class LmsLearningPathItem extends BaseModel
{
    protected $table = 'lms_learning_path_items';

    protected $casts = [
        'unlock_rule' => DripReleaseType::class,
        'unlock_at' => 'datetime',
        'unlock_after_days' => 'integer',
        'position' => 'integer',
        'sort_order' => 'integer',
        'is_required' => 'boolean',
        'settings' => 'array',
    ];

    public function learningPath(): BelongsTo
    {
        return $this->belongsTo(LmsLearningPath::class, 'learning_path_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(LmsCourseSection::class, 'section_id');
    }

    public function moduleTitle(): string
    {
        return (string) ($this->title ?: $this->section?->title ?: 'Modul '.$this->position);
    }
}
