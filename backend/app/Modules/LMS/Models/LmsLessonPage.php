<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\LessonPageType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Halaman lesson; halaman bertipe QUESTION punya cabang jawaban (§37). */
class LmsLessonPage extends BaseModel
{
    protected $table = 'lms_lesson_pages';

    protected $casts = [
        'page_type' => LessonPageType::class,
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(LmsLesson::class, 'lesson_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsQuestion::class, 'question_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(LmsLessonBranch::class, 'page_id')->orderBy('sort_order');
    }

    public function isQuestion(): bool
    {
        return $this->page_type === LessonPageType::QUESTION;
    }
}
