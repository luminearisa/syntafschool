<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Forecasting\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrForecastScenario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrForecastAssumption extends Model
{
    protected $table = 'hr_forecast_assumptions';

    protected $guarded = [];

    protected $casts = [
        'forecast_scenario_id' => 'integer',
        'key' => 'string',
        'label' => 'string',
        'value_type' => 'string',
        'value' => 'decimal:2',
        'value_json' => 'array',
        'source' => 'string',
        'evidence' => 'array',
        'owner_role' => 'string',
        'meta' => 'array',
    ];

    public function forecastScenario(): BelongsTo
    {
        return $this->belongsTo(HrForecastScenario::class, 'forecast_scenario_id');
    }
}
