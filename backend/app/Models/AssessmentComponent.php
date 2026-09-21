<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bagian penilaian yang lebih rinci dari satu assessment.
 *
 * Misalnya praktik kimia dibagi menjadi persiapan, keselamatan, proses, hasil,
 * dan laporan — masing-masing punya bobot sendiri di dalam satu nilai akhir
 * praktik tersebut.
 */
class AssessmentComponent extends BaseModel
{
    protected $casts = [
        'weight' => 'decimal:2',
        'max_score' => 'decimal:2',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
