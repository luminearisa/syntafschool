<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrSkill;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSkillGovernance extends Model
{
    protected $table = 'hr_skill_governance';

    protected $guarded = [];

    protected $casts = [
        'skill_id' => 'integer',
        'skill_code' => 'string',
        'policy' => 'string',
        'status' => 'string',
        'issue' => 'string',
        'steward_employee_id' => 'integer',
        'last_reviewed_at' => 'datetime',
        'resolution_notes' => 'string',
        'meta' => 'array',
    ];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(HrSkill::class, 'skill_id');
    }
}
