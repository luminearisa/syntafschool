<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evaluasi program supervisi dengan model CIPP. */
class SupProgramEvaluation extends BaseModel
{
    protected $table = 'sup_program_evaluations';

    protected $casts = [
        'context' => 'array',
        'input' => 'array',
        'process' => 'array',
        'product' => 'array',
        'context_score' => 'float',
        'input_score' => 'float',
        'process_score' => 'float',
        'product_score' => 'float',
        'stakeholders' => 'array',
        'finalized_at' => 'datetime',
    ];

    /** Rata-rata empat dimensi CIPP, atau null bila belum lengkap. */
    public function averageScore(): ?float
    {
        $values = array_filter([
            $this->context_score,
            $this->input_score,
            $this->process_score,
            $this->product_score,
        ], fn ($value) => $value !== null);

        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(SupProgram::class, 'program_id');
    }
}
