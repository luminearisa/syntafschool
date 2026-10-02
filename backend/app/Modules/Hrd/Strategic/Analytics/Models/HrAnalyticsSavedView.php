<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAnalyticsSavedView extends Model
{
    protected $table = 'hr_analytics_saved_views';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'owner_user_id' => 'integer',
        'entity' => 'string',
        'query_plan' => 'array',
        'filters' => 'array',
        'natural_language' => 'string',
        'shared' => 'boolean',
        'last_run_at' => 'datetime',
        'meta' => 'array',
    ];


}
