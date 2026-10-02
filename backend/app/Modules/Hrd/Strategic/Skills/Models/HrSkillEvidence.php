<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrSkill;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSkillEvidence extends Model
{
    protected $table = 'hr_skill_evidence';

    protected $guarded = [];

    protected $casts = [
        'employee_id' => 'integer',
        'skill_id' => 'integer',
        'skill_code' => 'string',
        'evidence_type' => 'string',
        'source_type' => 'string',
        'source_id' => 'integer',
        'level' => 'string',
        'level_ordinal' => 'integer',
        'confidence' => 'decimal:2',
        'verified_by' => 'integer',
        'verified_at' => 'datetime',
        'observed_at' => 'date:Y-m-d',
        'expiry_date' => 'date:Y-m-d',
        'weight' => 'decimal:2',
        'details' => 'array',
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
