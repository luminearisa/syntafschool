<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrMetricLineage extends Model
{
    protected $table = 'hr_metric_lineage';

    protected $guarded = [];

    protected $casts = [
        'metric_code' => 'string',
        'source_table' => 'string',
        'source_field' => 'string',
        'filter_expression' => 'string',
        'calculation' => 'string',
        'period_basis' => 'string',
        'notes' => 'string',
        'verified_by' => 'integer',
        'verified_at' => 'datetime',
        'meta' => 'array',
    ];


}
