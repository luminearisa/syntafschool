<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pilihan jawaban sebuah pertanyaan tambahan.
 */
class PpdbQuestionOption extends BaseModel
{
    protected $casts = ['order' => 'integer'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(PpdbQuestion::class, 'question_id');
    }
}