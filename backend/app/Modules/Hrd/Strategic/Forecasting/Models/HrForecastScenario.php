<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Forecasting\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrForecastAssumption;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceScenario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrForecastScenario extends Model
{
    protected $table = 'hr_forecast_scenarios';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'base_model_code' => 'string',
        'scenario_type' => 'string',
        'assumptions' => 'array',
        'link_scenario_id' => 'integer',
        'status' => 'string',
        'generated_at' => 'datetime',
        'meta' => 'array',
    ];

    public function linkScenario(): BelongsTo
    {
        return $this->belongsTo(HrWorkforceScenario::class, 'link_scenario_id');
    }

    public function assumptions(): HasMany
    {
        return $this->hasMany(HrForecastAssumption::class, 'forecast_scenario_id');
    }
}
