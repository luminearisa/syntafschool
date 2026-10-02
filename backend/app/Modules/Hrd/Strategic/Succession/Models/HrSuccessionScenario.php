<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Succession\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Succession\Models\HrCriticalPosition;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSuccessionScenario extends Model
{
    protected $table = 'hr_succession_scenarios';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'critical_position_id' => 'integer',
        'name' => 'string',
        'departure_type' => 'string',
        'results' => 'array',
        'best_candidate_employee_id' => 'integer',
        'gaps' => 'array',
        'development_required' => 'array',
        'created_by' => 'integer',
        'simulated_at' => 'datetime',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function criticalPosition(): BelongsTo
    {
        return $this->belongsTo(HrCriticalPosition::class, 'critical_position_id');
    }
}
