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
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(SupProgram::class, 'program_id');
    }
}
