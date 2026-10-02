<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceCostForecast;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceForecast;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceScenarioAssumption;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWorkforceScenario extends Model
{
    protected $table = 'hr_workforce_scenarios';

    protected $guarded = [];

    protected $casts = [
        'plan_id' => 'integer',
        'code' => 'string',
        'name' => 'string',
        'scenario_type' => 'string',
        'assumptions' => 'array',
        'inputs' => 'array',
        'status' => 'string',
        'baseline_scenario_id' => 'integer',
        'created_by' => 'integer',
        'simulated_at' => 'datetime',
        'results' => 'array',
        'comparison' => 'array',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function baselineScenario(): BelongsTo
    {
        return $this->belongsTo(HrWorkforceScenario::class, 'baseline_scenario_id');
    }

    public function assumptions(): HasMany
    {
        return $this->hasMany(HrWorkforceScenarioAssumption::class, 'scenario_id');
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(HrWorkforceForecast::class, 'scenario_id');
    }

    public function costForecasts(): HasMany
    {
        return $this->hasMany(HrWorkforceCostForecast::class, 'scenario_id');
    }
}
