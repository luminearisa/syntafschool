<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Forecasting\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrForecastRun;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrForecastOutput extends Model
{
    protected $table = 'hr_forecast_outputs';

    protected $guarded = [];

    protected $casts = [
        'run_id' => 'integer',
        'period' => 'string',
        'metric_key' => 'string',
        'value' => 'decimal:2',
        'lower_bound' => 'decimal:2',
        'upper_bound' => 'decimal:2',
        'confidence' => 'decimal:2',
        'method' => 'string',
        'drivers' => 'array',
        'meta' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(HrForecastRun::class, 'run_id');
    }
}
