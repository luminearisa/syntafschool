<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrBiasMonitoring extends Model
{
    protected $table = 'hr_bias_monitoring';

    protected $guarded = [];

    protected $casts = [
        'model_code' => 'string',
        'period' => 'string',
        'context' => 'string',
        'group_dimension' => 'string',
        'selection_rate' => 'decimal:2',
        'reference_rate' => 'decimal:2',
        'disparity_ratio' => 'decimal:2',
        'flag' => 'string',
        'evidence' => 'array',
        'notes' => 'string',
        'generated_at' => 'datetime',
        'meta' => 'array',
    ];


}
