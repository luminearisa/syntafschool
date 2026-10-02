<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceScenario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWorkforceCostForecast extends Model
{
    protected $table = 'hr_workforce_cost_forecasts';

    protected $guarded = [];

    protected $casts = [
        'plan_id' => 'integer',
        'scenario_id' => 'integer',
        'organization_id' => 'integer',
        'period' => 'string',
        'salary_amount' => 'decimal:2',
        'allowance_amount' => 'decimal:2',
        'benefit_amount' => 'decimal:2',
        'overtime_amount' => 'decimal:2',
        'recruitment_amount' => 'decimal:2',
        'training_amount' => 'decimal:2',
        'severance_reference_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'budget_amount' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'headcount' => 'integer',
        'assumptions' => 'array',
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
