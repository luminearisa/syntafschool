<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Soal yang dipakai pada satu percobaan (snapshot + mark) (§22–23). */
class LmsQuizAttemptQuestion extends BaseModel
{
    protected $table = 'lms_quiz_attempt_questions';

    protected $casts = [
        'marks' => 'decimal:2',
        'sort_order' => 'integer',
        'snapshot' => 'array',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LmsQuizAttempt::class, 'attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsQuestion::class, 'question_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionVersion::class, 'question_version_id');
    }
}
