<?php

namespace App\Modules\CBT\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtQuestionOption extends CbtModel
{
    protected $table = 'cbt_question_options';

    protected $casts = [
        'is_correct' => 'boolean',
        'match_key' => 'string',
        'position' => 'integer',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class, 'question_id');
    }
}
