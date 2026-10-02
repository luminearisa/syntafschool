<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Strategic\People\Models\HrPayEquityFinding;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPayEquityAnalysis extends Model
{
    protected $table = 'hr_pay_equity_analyses';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'scope_organization_id' => 'integer',
        'comparison_group' => 'array',
        'period' => 'string',
        'method' => 'string',
        'status' => 'string',
        'findings' => 'array',
        'anomaly_count' => 'integer',
        'result_summary' => 'array',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function scopeOrganization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'scope_organization_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(HrPayEquityFinding::class, 'analysis_id');
    }
}
