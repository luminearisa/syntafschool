<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Snapshot versi soal yang sudah pernah dipakai attempt (§22). */
class LmsQuestionVersion extends BaseModel
{
    protected $table = 'lms_question_versions';

    protected $casts = [
        'default_mark' => 'decimal:2',
        'settings' => 'array',
        'options' => 'array',
        'version' => 'integer',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsQuestion::class, 'question_id');
    }

    /** Bentuk snapshot agar identik dengan `LmsQuizAttemptQuestion::snapshot`. */
    public function toSnapshot(): array
    {
        return [
            'question_id' => (int) $this->question_id,
            'version' => (int) $this->version,
            'question_type' => $this->question_type,
            'name' => null,
            'question_text' => $this->question_text,
            'general_feedback' => $this->general_feedback,
            'default_mark' => (float) $this->default_mark,
            'settings' => $this->settings,
            'options' => $this->options ?? [],
        ];
    }
}
