<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAttritionSnapshot extends Model
{
    protected $table = 'hr_attrition_snapshots';

    protected $guarded = [];

    protected $casts = [
        'period' => 'string',
        'granularity' => 'string',
        'dimension' => 'string',
        'dimension_key' => 'string',
        'headcount_start' => 'integer',
        'headcount_end' => 'integer',
        'leavers' => 'integer',
        'turnover_rate' => 'decimal:2',
        'avg_tenure_months' => 'decimal:2',
        'voluntary' => 'integer',
        'involuntary' => 'integer',
        'trend_ref' => 'array',
        'generated_at' => 'datetime',
        'meta' => 'array',
    ];


}
