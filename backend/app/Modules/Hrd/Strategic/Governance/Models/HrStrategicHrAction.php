<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRecommendation;
use App\Modules\Hrd\Strategic\Governance\Models\HrRisk;
use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrActionItem;
use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrActionResult;
use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrActionReview;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrStrategicHrAction extends Model
{
    protected $table = 'hr_strategic_hr_actions';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'title' => 'string',
        'objective' => 'string',
        'source_type' => 'string',
        'source_id' => 'integer',
        'linked_risk_id' => 'integer',
        'linked_recommendation_id' => 'integer',
        'workforce_requirement' => 'string',
        'skill_requirement' => 'string',
        'talent_strategy' => 'string',
        'owner_employee_id' => 'integer',
        'owner_role' => 'string',
        'owner_organization_id' => 'integer',
        'priority' => 'string',
        'status' => 'string',
        'start_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
        'budget_amount' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'measurement' => 'array',
        'workflow_reference' => 'string',
        'approval_status' => 'string',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function linkedRisk(): BelongsTo
    {
        return $this->belongsTo(HrRisk::class, 'linked_risk_id');
    }

    public function linkedRecommendation(): BelongsTo
    {
        return $this->belongsTo(HrAiRecommendation::class, 'linked_recommendation_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'owner_employee_id');
    }

    public function ownerOrganization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'owner_organization_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(HrStrategicHrActionItem::class, 'action_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(HrStrategicHrActionResult::class, 'action_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(HrStrategicHrActionReview::class, 'action_id');
    }
}
