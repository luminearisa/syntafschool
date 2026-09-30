<?php

namespace App\Modules\CBT\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtQuestionRevision extends CbtModel
{
    protected $table = 'cbt_question_revisions';

    protected $casts = [
        'snapshot' => 'array',
        'version' => 'integer',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class, 'question_id');
    }
}
