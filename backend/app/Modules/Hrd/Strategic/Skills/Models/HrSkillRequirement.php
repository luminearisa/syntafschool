<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrSkill;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSkillRequirement extends Model
{
    protected $table = 'hr_skill_requirements';

    protected $guarded = [];

    protected $casts = [
        'scope_type' => 'string',
        'scope_id' => 'integer',
        'skill_id' => 'integer',
        'skill_code' => 'string',
        'requirement_type' => 'string',
        'required_level' => 'string',
        'required_level_ordinal' => 'integer',
        'weight' => 'decimal:2',
        'rationale' => 'string',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'source' => 'string',
        'status' => 'string',
        'meta' => 'array',
    ];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(HrSkill::class, 'skill_id');
    }
}
