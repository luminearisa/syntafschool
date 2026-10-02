<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Models\HrSkill;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPositionSkill extends Model
{
    protected $table = 'hr_position_skills';

    protected $guarded = [];

    protected $casts = [
        'position_id' => 'integer',
        'skill_id' => 'integer',
        'skill_code' => 'string',
        'requirement_type' => 'string',
        'required_level' => 'string',
        'required_ordinal' => 'integer',
        'is_critical' => 'boolean',
        'meta' => 'array',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(HrSkill::class, 'skill_id');
    }
}
