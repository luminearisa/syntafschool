<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSkillLevel extends Model
{
    protected $table = 'hr_skill_levels';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'ordinal' => 'integer',
        'description' => 'string',
        'evidence_expectation' => 'array',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];


}
