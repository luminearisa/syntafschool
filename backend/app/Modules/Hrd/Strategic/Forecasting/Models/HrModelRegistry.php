<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Forecasting\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrModelRegistry extends Model
{
    protected $table = 'hr_model_registry';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'model_type' => 'string',
        'version' => 'string',
        'purpose' => 'string',
        'training_period_start' => 'date:Y-m-d',
        'training_period_end' => 'date:Y-m-d',
        'features' => 'array',
        'metrics' => 'array',
        'approval_status' => 'string',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'monitoring_notes' => 'string',
        'status' => 'string',
        'meta' => 'array',
    ];


}
