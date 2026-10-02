<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrSkill;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSkillGap extends Model
{
    protected $table = 'hr_skill_gaps';

    protected $guarded = [];

    protected $casts = [
        'employee_id' => 'integer',
        'skill_id' => 'integer',
        'skill_code' => 'string',
        'scope_type' => 'string',
        'scope_id' => 'integer',
        'current_level' => 'string',
        'current_ordinal' => 'integer',
        'required_level' => 'string',
        'required_ordinal' => 'integer',
        'gap' => 'integer',
        'priority' => 'string',
        'development_options' => 'array',
        'estimated_cost' => 'decimal:2',
        'status' => 'string',
        'detected_at' => 'datetime',
        'plan_id' => 'integer',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(HrSkill::class, 'skill_id');
    }
}
