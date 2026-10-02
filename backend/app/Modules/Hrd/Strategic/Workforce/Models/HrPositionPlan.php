<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Models\HrPosition;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPositionPlan extends Model
{
    protected $table = 'hr_position_plans';

    protected $guarded = [];

    protected $casts = [
        'plan_id' => 'integer',
        'position_id' => 'integer',
        'organization_id' => 'integer',
        'action' => 'string',
        'from_organization_id' => 'integer',
        'to_organization_id' => 'integer',
        'from_grade_code' => 'string',
        'to_grade_code' => 'string',
        'headcount_delta' => 'integer',
        'cost_delta' => 'decimal:2',
        'effective_date' => 'date:Y-m-d',
        'target_incumbent_employee_id' => 'integer',
        'status' => 'string',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function fromOrganization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'from_organization_id');
    }

    public function toOrganization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'to_organization_id');
    }
}
