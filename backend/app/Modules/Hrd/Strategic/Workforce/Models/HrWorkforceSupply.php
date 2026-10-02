<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrDepartment;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Models\HrPosition;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWorkforceSupply extends Model
{
    protected $table = 'hr_workforce_supply';

    protected $guarded = [];

    protected $casts = [
        'plan_id' => 'integer',
        'version' => 'integer',
        'organization_id' => 'integer',
        'department_id' => 'integer',
        'position_id' => 'integer',
        'location_id' => 'integer',
        'as_of' => 'date:Y-m-d',
        'active_headcount' => 'integer',
        'open_positions' => 'integer',
        'expected_retirements' => 'integer',
        'contract_expiries' => 'integer',
        'expected_attrition' => 'decimal:2',
        'internal_mobility' => 'integer',
        'available_skills' => 'array',
        'talent_pool_size' => 'integer',
        'capacity_hours' => 'decimal:2',
        'cost_amount' => 'decimal:2',
        'breakdown' => 'array',
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }
}
