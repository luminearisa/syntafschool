<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Konfigurasi lesson (§37). */
class LmsLesson extends BaseModel
{
    protected $table = 'lms_lessons';

    protected $casts = [
        'require_all_pages' => 'boolean',
        'allow_review' => 'boolean',
        'max_attempts' => 'integer',
        'settings' => 'array',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(LmsLessonPage::class, 'lesson_id')->orderBy('sort_order');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LmsLessonProgress::class, 'lesson_id');
    }

    /** @return list<int> */
    public function requiredPageIds(): array
    {
        $ids = $this->pages->where('is_required', true)->pluck('id')->all();

        return array_map('intval', $ids);
    }
}
