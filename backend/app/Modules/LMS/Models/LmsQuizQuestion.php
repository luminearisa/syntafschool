<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Soal pada kuis — manual (`question_id`) atau slot acak dari kategori (§23).
 */
class LmsQuizQuestion extends BaseModel
{
    protected $table = 'lms_quiz_questions';

    protected $casts = [
        'marks' => 'decimal:2',
        'question_count' => 'integer',
        'sort_order' => 'integer',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(LmsQuiz::class, 'quiz_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsQuestion::class, 'question_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionCategory::class, 'category_id');
    }

    public function isRandomSlot(): bool
    {
        return $this->question_id === null && $this->category_id !== null;
    }
}
