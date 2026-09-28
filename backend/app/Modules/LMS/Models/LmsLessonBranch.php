<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cabang jawaban: benar → halaman A, salah → halaman B (§37). */
class LmsLessonBranch extends BaseModel
{
    protected $table = 'lms_lesson_branches';

    protected $casts = [
        'is_correct' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(LmsLessonPage::class, 'page_id');
    }

    public function nextPage(): BelongsTo
    {
        return $this->belongsTo(LmsLessonPage::class, 'next_page_id');
    }
}
