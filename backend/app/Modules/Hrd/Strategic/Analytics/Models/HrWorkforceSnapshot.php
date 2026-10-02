<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWorkforceSnapshot extends Model
{
    protected $table = 'hr_workforce_snapshots';

    protected $guarded = [];

    protected $casts = [
        'snapshot_date' => 'date:Y-m-d',
        'granularity' => 'string',
        'scope' => 'string',
        'organization_id' => 'integer',
        'headcount' => 'integer',
        'active_headcount' => 'integer',
        'open_positions' => 'integer',
        'positions' => 'integer',
        'avg_tenure_months' => 'decimal:2',
        'payroll_cost' => 'decimal:2',
        'benefit_cost' => 'decimal:2',
        'absence_days' => 'decimal:2',
        'absence_rate' => 'decimal:2',
        'performance_avg' => 'decimal:2',
        'skill_coverage' => 'decimal:2',
        'turnover_signals' => 'array',
        'breakdown' => 'array',
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }
}
