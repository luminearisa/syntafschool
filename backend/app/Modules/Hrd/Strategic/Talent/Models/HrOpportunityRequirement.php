<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Talent\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrOpportunityRequirement extends Model
{
    protected $table = 'hr_opportunity_requirements';

    protected $guarded = [];

    protected $casts = [
        'opportunity_id' => 'integer',
        'requirement_type' => 'string',
        'key' => 'string',
        'required_level' => 'string',
        'required_value' => 'decimal:2',
        'weight' => 'decimal:2',
        'is_mandatory' => 'boolean',
        'meta' => 'array',
    ];


}
