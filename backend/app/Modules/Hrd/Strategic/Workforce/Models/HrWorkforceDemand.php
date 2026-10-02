<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrDepartment;
use App\Modules\Hrd\Models\HrJob;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Models\HrPosition;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWorkforceDemand extends Model
{
    protected $table = 'hr_workforce_demand';

    protected $guarded = [];

    protected $casts = [
        'plan_id' => 'integer',
        'version' => 'integer',
        'organization_id' => 'integer',
        'department_id' => 'integer',
        'position_id' => 'integer',
        'job_id' => 'integer',
        'location_id' => 'integer',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'required_headcount' => 'integer',
        'required_skills' => 'array',
        'required_capacity' => 'decimal:2',
        'budget_amount' => 'decimal:2',
        'driver' => 'string',
        'driver_reference' => 'string',
        'priority' => 'string',
        'status' => 'string',
        'assumptions' => 'array',
        'created_by' => 'integer',
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

    public function job(): BelongsTo
    {
        return $this->belongsTo(HrJob::class, 'job_id');
    }
}
