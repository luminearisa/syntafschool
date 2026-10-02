<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Forecasting\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrForecastModel extends Model
{
    protected $table = 'hr_forecast_models';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'purpose' => 'string',
        'method' => 'string',
        'version' => 'integer',
        'parameters' => 'array',
        'features' => 'array',
        'status' => 'string',
        'approval_by' => 'integer',
        'approved_at' => 'datetime',
        'metrics' => 'array',
        'owner_role' => 'string',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];


}
