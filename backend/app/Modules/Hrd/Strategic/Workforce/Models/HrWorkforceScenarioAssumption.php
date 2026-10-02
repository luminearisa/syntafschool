<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceScenario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWorkforceScenarioAssumption extends Model
{
    protected $table = 'hr_workforce_scenario_assumptions';

    protected $guarded = [];

    protected $casts = [
        'scenario_id' => 'integer',
        'key' => 'string',
        'label' => 'string',
        'value_type' => 'string',
        'value' => 'decimal:2',
        'value_json' => 'array',
        'source' => 'string',
        'is_driver' => 'boolean',
        'evidence' => 'array',
        'meta' => 'array',
    ];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(HrWorkforceScenario::class, 'scenario_id');
    }
}
