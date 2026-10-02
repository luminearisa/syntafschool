<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Strategic\Workforce\Models\HrPositionPlan;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceCostForecast;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceDemand;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceForecast;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceGap;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforcePlanVersion;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceResourceRequirement;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceScenario;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceSupply;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWorkforcePlan extends Model
{
    protected $table = 'hr_workforce_plans';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'organization_id' => 'integer',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'planning_horizon' => 'string',
        'plan_type' => 'string',
        'status' => 'string',
        'owner_id' => 'integer',
        'version' => 'integer',
        'assumptions' => 'array',
        'strategy_reference' => 'string',
        'budget_amount' => 'decimal:2',
        'currency' => 'string',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function demands(): HasMany
    {
        return $this->hasMany(HrWorkforceDemand::class, 'plan_id');
    }

    public function supplies(): HasMany
    {
        return $this->hasMany(HrWorkforceSupply::class, 'plan_id');
    }

    public function gaps(): HasMany
    {
        return $this->hasMany(HrWorkforceGap::class, 'plan_id');
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(HrWorkforceScenario::class, 'plan_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(HrWorkforcePlanVersion::class, 'plan_id');
    }

    public function positionPlans(): HasMany
    {
        return $this->hasMany(HrPositionPlan::class, 'plan_id');
    }

    public function costForecasts(): HasMany
    {
        return $this->hasMany(HrWorkforceCostForecast::class, 'plan_id');
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(HrWorkforceForecast::class, 'plan_id');
    }

    public function resourceRequirements(): HasMany
    {
        return $this->hasMany(HrWorkforceResourceRequirement::class, 'plan_id');
    }
}
