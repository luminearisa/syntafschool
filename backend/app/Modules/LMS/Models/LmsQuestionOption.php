<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pilihan jawaban / pasangan menjodohkan (§16, §21). */
class LmsQuestionOption extends BaseModel
{
    protected $table = 'lms_question_options';

    protected $casts = [
        'is_correct' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsQuestion::class, 'question_id');
    }
}
