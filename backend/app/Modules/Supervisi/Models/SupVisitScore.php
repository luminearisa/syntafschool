<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Agregat skor per bagian (mempercepat laporan tanpa menghitung ulang). */
class SupVisitScore extends BaseModel
{
    protected $table = 'sup_visit_scores';

    protected $casts = [
        'raw_score' => 'float',
        'max_score' => 'float',
        'weighted_score' => 'float',
        'percent' => 'float',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(SupSection::class, 'section_id');
    }
}
