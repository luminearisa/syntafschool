<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jawaban siswa per soal — audit lengkap, bukan hanya skor akhir (§26). */
class LmsQuizAnswer extends BaseModel
{
    protected $table = 'lms_quiz_answers';

    protected $casts = [
        'answer_data' => 'array',
        'marks' => 'decimal:2',
        'is_correct' => 'boolean',
        'graded_at' => 'datetime',
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
