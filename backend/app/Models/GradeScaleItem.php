<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris rentang nilai pada sebuah skala nilai.
 */
class GradeScaleItem extends BaseModel
{
    protected $casts = [
        'min_score' => 'decimal:2',
        'max_score' => 'decimal:2',
    ];

    public function gradeScale(): BelongsTo
    {
        return $this->belongsTo(GradeScale::class);
    }

    /**
     * Apakah nilai angka ini jatuh pada rentang tersebut.
     */
    public function covers(float $score): bool
    {
        return $score >= (float) $this->min_score && $score <= (float) $this->max_score;
    }
}
