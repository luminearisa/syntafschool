<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Talent\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrDepartment;
use App\Modules\Hrd\Models\HrJob;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Strategic\Talent\Models\HrOpportunityRequirement;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrTalentOpportunity extends Model
{
    protected $table = 'hr_talent_opportunities';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'opportunity_type_code' => 'string',
        'title' => 'string',
        'description' => 'string',
        'organization_id' => 'integer',
        'department_id' => 'integer',
        'location_id' => 'integer',
        'position_id' => 'integer',
        'job_id' => 'integer',
        'project_reference' => 'string',
        'duration_days' => 'integer',
        'allocation_percent' => 'decimal:2',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'required_skills' => 'array',
        'preferred_skills' => 'array',
        'min_experience_years' => 'decimal:2',
        'required_certification' => 'string',
        'visibility' => 'string',
        'status' => 'string',
        'spots' => 'integer',
        'filled_by_employee_id' => 'integer',
        'created_by' => 'integer',
        'published_at' => 'datetime',
        'closes_at' => 'datetime',
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

    public function requirements(): HasMany
    {
        return $this->hasMany(HrOpportunityRequirement::class, 'opportunity_id');
    }
}
