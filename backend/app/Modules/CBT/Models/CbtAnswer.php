<?php

namespace App\Modules\CBT\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtAnswer extends CbtModel
{
    protected $table = 'cbt_answers';

    protected $casts = [
        'response' => 'array',
        'awarded' => 'decimal:2',
        'max_points' => 'decimal:2',
        'is_correct' => 'boolean',
        'flagged' => 'boolean',
        'auto_graded' => 'boolean',
        'graded_at' => 'datetime',
        'answered_at' => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CbtAttempt::class, 'attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class, 'question_id');
    }
}
