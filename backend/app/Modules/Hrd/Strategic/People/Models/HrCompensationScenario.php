<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrCompensationScenario extends Model
{
    protected $table = 'hr_compensation_scenarios';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'scenario_type' => 'string',
        'increase_percent' => 'decimal:2',
        'distribution_method' => 'string',
        'scope' => 'array',
        'population_count' => 'integer',
        'budget_amount' => 'decimal:2',
        'projected_total_cost' => 'decimal:2',
        'budget_variance' => 'decimal:2',
        'by_department' => 'array',
        'employee_impact' => 'array',
        'status' => 'string',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'simulated_at' => 'datetime',
        'notes' => 'string',
        'meta' => 'array',
    ];


}
