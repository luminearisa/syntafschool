<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Forecasting\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrForecastRun;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrForecastInput extends Model
{
    protected $table = 'hr_forecast_inputs';

    protected $guarded = [];

    protected $casts = [
        'run_id' => 'integer',
        'input_type' => 'string',
        'key' => 'string',
        'value' => 'decimal:2',
        'value_json' => 'array',
        'period' => 'string',
        'source' => 'string',
        'meta' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(HrForecastRun::class, 'run_id');
    }
}
