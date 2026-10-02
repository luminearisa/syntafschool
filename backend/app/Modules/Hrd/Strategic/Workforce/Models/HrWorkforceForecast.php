<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceScenario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWorkforceForecast extends Model
{
    protected $table = 'hr_workforce_forecasts';

    protected $guarded = [];

    protected $casts = [
        'plan_id' => 'integer',
        'scenario_id' => 'integer',
        'forecast_model_code' => 'string',
        'forecast_type' => 'string',
        'organization_id' => 'integer',
        'period' => 'string',
        'horizon_months' => 'integer',
        'value' => 'decimal:2',
        'lower_bound' => 'decimal:2',
        'upper_bound' => 'decimal:2',
        'confidence' => 'decimal:2',
        'method' => 'string',
        'inputs' => 'array',
        'generated_at' => 'datetime',
        'meta' => 'array',
    ];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(HrWorkforceScenario::class, 'scenario_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }
}
