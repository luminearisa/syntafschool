<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWorkforcePlanVersion extends Model
{
    protected $table = 'hr_workforce_plan_versions';

    protected $guarded = [];

    protected $casts = [
        'plan_id' => 'integer',
        'version' => 'integer',
        'status' => 'string',
        'snapshot' => 'array',
        'assumptions' => 'array',
        'demand_summary' => 'array',
        'supply_summary' => 'array',
        'gap_summary' => 'array',
        'cost_forecast' => 'decimal:2',
        'created_by' => 'integer',
        'activated_at' => 'datetime',
        'notes' => 'string',
        'meta' => 'array',
    ];


}
