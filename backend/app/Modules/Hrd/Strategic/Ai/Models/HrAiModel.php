<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiModel extends Model
{
    protected $table = 'hr_ai_models';

    protected $guarded = [];

    protected $casts = [
        'provider_code' => 'string',
        'code' => 'string',
        'name' => 'string',
        'model_type' => 'string',
        'context_window' => 'integer',
        'input_cost_per_1k' => 'decimal:2',
        'output_cost_per_1k' => 'decimal:2',
        'latency_class' => 'string',
        'data_classification_limit' => 'string',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];


}
