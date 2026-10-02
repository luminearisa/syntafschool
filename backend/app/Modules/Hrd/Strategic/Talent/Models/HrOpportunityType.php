<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Talent\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrOpportunityType extends Model
{
    protected $table = 'hr_opportunity_types';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'category' => 'string',
        'duration_days' => 'integer',
        'allocation_default' => 'decimal:2',
        'is_paid' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];


}
