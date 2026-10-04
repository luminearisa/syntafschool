<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\EvaluationStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Evaluasi satu penawaran oleh seorang evaluator. Tabel: `proc_quotation_evaluations`. */
class QuotationEvaluation extends ProcurementModel
{
    protected $table = 'proc_quotation_evaluations';

    protected $casts = [
        'total_score' => 'decimal:2',
        'weighted_score' => 'decimal:2',
        'evaluated_at' => 'datetime',
        'status' => EvaluationStatus::class,
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationEvaluationItem::class, 'evaluation_id');
    }
}
