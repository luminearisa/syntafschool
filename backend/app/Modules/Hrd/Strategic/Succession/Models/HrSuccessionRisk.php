<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Succession\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Succession\Models\HrCriticalPosition;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionPlan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSuccessionRisk extends Model
{
    protected $table = 'hr_succession_risks';

    protected $guarded = [];

    protected $casts = [
        'critical_position_id' => 'integer',
        'plan_id' => 'integer',
        'risk_type' => 'string',
        'severity' => 'string',
        'successor_count' => 'integer',
        'readiness_summary' => 'array',
        'evidence' => 'array',
        'recommended_action' => 'string',
        'detected_at' => 'datetime',
        'status' => 'string',
        'meta' => 'array',
    ];

    public function criticalPosition(): BelongsTo
    {
        return $this->belongsTo(HrCriticalPosition::class, 'critical_position_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(HrSuccessionPlan::class, 'plan_id');
    }
}
