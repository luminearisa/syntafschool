<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Succession\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionPlan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSuccessionCandidate extends Model
{
    protected $table = 'hr_succession_candidates';

    protected $guarded = [];

    protected $casts = [
        'plan_id' => 'integer',
        'employee_id' => 'integer',
        'candidate_type' => 'string',
        'readiness' => 'string',
        'readiness_score' => 'decimal:2',
        'skill_gap_snapshot' => 'array',
        'evidence' => 'array',
        'development_plan' => 'array',
        'impact_of_loss' => 'string',
        'risk_of_loss' => 'string',
        'rank' => 'integer',
        'status' => 'string',
        'reviewed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(HrSuccessionPlan::class, 'plan_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
