<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Succession\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionPlan;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionRisk;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionScenario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrCriticalPosition extends Model
{
    protected $table = 'hr_critical_positions';

    protected $guarded = [];

    protected $casts = [
        'position_id' => 'integer',
        'criticality' => 'string',
        'business_impact' => 'string',
        'replacement_difficulty' => 'string',
        'succession_required' => 'boolean',
        'min_successors' => 'integer',
        'incumbent_employee_id' => 'integer',
        'retirement_risk' => 'string',
        'turnover_risk' => 'string',
        'status' => 'string',
        'flagged_by' => 'integer',
        'flagged_at' => 'datetime',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function incumbent(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'incumbent_employee_id');
    }

    public function successionPlans(): HasMany
    {
        return $this->hasMany(HrSuccessionPlan::class, 'critical_position_id');
    }

    public function risks(): HasMany
    {
        return $this->hasMany(HrSuccessionRisk::class, 'critical_position_id');
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(HrSuccessionScenario::class, 'critical_position_id');
    }
}
