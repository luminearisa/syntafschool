<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Skor satu kriteria tahsin sesuai rubrik.
 */
class TahfizTahsinScore extends BaseModel
{
    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function tahsinRecord(): BelongsTo
    {
        return $this->belongsTo(TahfizTahsinRecord::class, 'tahsin_record_id');
    }

    public function rubricItem(): BelongsTo
    {
        return $this->belongsTo(TahfizRubricItem::class, 'rubric_item_id');
    }
}
