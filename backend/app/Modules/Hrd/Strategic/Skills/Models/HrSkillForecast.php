<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Models\HrSkill;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSkillForecast extends Model
{
    protected $table = 'hr_skill_forecasts';

    protected $guarded = [];

    protected $casts = [
        'skill_id' => 'integer',
        'skill_code' => 'string',
        'skill_domain' => 'string',
        'organization_id' => 'integer',
        'period' => 'string',
        'current_supply' => 'decimal:2',
        'future_demand' => 'decimal:2',
        'gap' => 'decimal:2',
        'development_capacity' => 'decimal:2',
        'hiring_alternative' => 'decimal:2',
        'scarcity_index' => 'decimal:2',
        'method' => 'string',
        'assumptions' => 'array',
        'generated_at' => 'datetime',
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
