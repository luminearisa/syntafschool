<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Models\HrSkill;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSkillDemand extends Model
{
    protected $table = 'hr_skill_demand';

    protected $guarded = [];

    protected $casts = [
        'skill_id' => 'integer',
        'skill_code' => 'string',
        'organization_id' => 'integer',
        'period' => 'string',
        'demand_type' => 'string',
        'required_headcount' => 'integer',
        'required_capacity' => 'decimal:2',
        'required_level' => 'string',
        'criticality' => 'string',
        'source' => 'string',
        'evidence' => 'array',
        'meta' => 'array',
    ];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(HrSkill::class, 'skill_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }
}
