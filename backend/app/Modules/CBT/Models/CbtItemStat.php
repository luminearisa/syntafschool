<?php

namespace App\Modules\CBT\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtItemStat extends CbtModel
{
    protected $table = 'cbt_item_stats';

    protected $casts = [
        'attempts' => 'integer',
        'correct' => 'integer',
        'p_value' => 'decimal:4',
        'discrimination' => 'decimal:4',
        'avg_points' => 'decimal:2',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class, 'question_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'session_id');
    }
}
