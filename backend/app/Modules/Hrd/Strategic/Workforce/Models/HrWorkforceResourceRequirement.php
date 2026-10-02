<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceScenario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWorkforceResourceRequirement extends Model
{
    protected $table = 'hr_workforce_resource_requirements';

    protected $guarded = [];

    protected $casts = [
        'plan_id' => 'integer',
        'scenario_id' => 'integer',
        'resource_type' => 'string',
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'needed_on' => 'date:Y-m-d',
        'source' => 'string',
        'status' => 'string',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(HrWorkforceScenario::class, 'scenario_id');
    }
}
