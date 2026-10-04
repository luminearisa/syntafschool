<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kriteria evaluasi penawaran (bobot configurable). Tabel: `proc_evaluation_criteria`.
 *
 * Bila `rfq_id` null, kriteria berlaku sebagai bawaan modul (dari
 * `config('procurement.evaluation_weights')`).
 */
class EvaluationCriteria extends ProcurementModel
{
    protected $table = 'proc_evaluation_criteria';

    protected $casts = [
        'weight' => 'decimal:2',
        'max_score' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }
}
