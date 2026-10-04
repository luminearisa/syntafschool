<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Skor per kriteria dalam satu evaluasi. Tabel: `proc_quotation_evaluation_items`. */
class QuotationEvaluationItem extends ProcurementModel
{
    protected $table = 'proc_quotation_evaluation_items';

    protected $casts = [
        'weight' => 'decimal:2',
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
    ];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(QuotationEvaluation::class, 'evaluation_id');
    }

    public function criteria(): BelongsTo
    {
        return $this->belongsTo(EvaluationCriteria::class, 'criteria_id');
    }
}
