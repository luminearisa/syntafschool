<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrIncentiveSimulation extends Model
{
    protected $table = 'hr_incentive_simulations';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'period' => 'string',
        'basis' => 'array',
        'eligible_count' => 'integer',
        'total_cost' => 'decimal:2',
        'budget_amount' => 'decimal:2',
        'budget_variance' => 'decimal:2',
        'distribution' => 'array',
        'top_recipients' => 'array',
        'status' => 'string',
        'created_by' => 'integer',
        'simulated_at' => 'datetime',
        'notes' => 'string',
        'meta' => 'array',
    ];


}
