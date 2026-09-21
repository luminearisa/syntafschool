<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kriteria dalam rubrik penilaian (mis. "Makhraj", "Tajwid").
 *
 * `level_descriptions` menyimpan deskripsi per tingkat skor:
 * {"1": "belum menguasai", "5": "sangat baik"}.
 */
class TahfizRubricItem extends BaseModel
{
    protected $casts = [
        'weight' => 'decimal:2',
        'order' => 'integer',
        'level_descriptions' => 'array',
    ];

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(TahfizRubric::class, 'rubric_id');
    }
}
