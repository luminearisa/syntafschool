<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Forecasting\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrForecastInput;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrForecastOutput;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrForecastRun extends Model
{
    protected $table = 'hr_forecast_runs';

    protected $guarded = [];

    protected $casts = [
        'model_code' => 'string',
        'code' => 'string',
        'purpose' => 'string',
        'scope' => 'string',
        'organization_id' => 'integer',
        'period' => 'string',
        'horizon_months' => 'integer',
        'status' => 'string',
        'inputs' => 'array',
        'outputs' => 'array',
        'summary' => 'array',
        'confidence' => 'decimal:2',
        'generated_by_user_id' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'idempotency_key' => 'string',
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(HrForecastInput::class, 'run_id');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(HrForecastOutput::class, 'run_id');
    }
}
