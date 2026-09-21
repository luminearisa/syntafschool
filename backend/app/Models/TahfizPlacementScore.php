<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Skor satu kriteria pada placement test (mis. aspek tilawah, kriteria tajwid).
 */
class TahfizPlacementScore extends BaseModel
{
    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function placementTest(): BelongsTo
    {
        return $this->belongsTo(TahfizPlacementTest::class, 'placement_test_id');
    }
}
