<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrSkill;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSkillAssessment extends Model
{
    protected $table = 'hr_skill_assessments';

    protected $guarded = [];

    protected $casts = [
        'employee_id' => 'integer',
        'skill_id' => 'integer',
        'skill_code' => 'string',
        'method' => 'string',
        'assessed_level' => 'string',
        'assessed_ordinal' => 'integer',
        'evidence_count' => 'integer',
        'assessor_employee_id' => 'integer',
        'assessed_at' => 'datetime',
        'confidence' => 'decimal:2',
        'notes' => 'string',
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

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'assessor_employee_id');
    }
}
