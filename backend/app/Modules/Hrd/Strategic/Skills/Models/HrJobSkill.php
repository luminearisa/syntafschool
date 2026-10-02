<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrJob;
use App\Modules\Hrd\Models\HrSkill;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrJobSkill extends Model
{
    protected $table = 'hr_job_skills';

    protected $guarded = [];

    protected $casts = [
        'job_id' => 'integer',
        'skill_id' => 'integer',
        'skill_code' => 'string',
        'requirement_type' => 'string',
        'required_level' => 'string',
        'required_ordinal' => 'integer',
        'is_critical' => 'boolean',
        'meta' => 'array',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(HrJob::class, 'job_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(HrSkill::class, 'skill_id');
    }
}
