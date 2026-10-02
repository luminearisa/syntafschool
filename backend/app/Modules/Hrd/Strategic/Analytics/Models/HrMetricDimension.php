<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Analytics\Models\HrMetricDefinition;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrMetricDimension extends Model
{
    protected $table = 'hr_metric_dimensions';

    protected $guarded = [];

    protected $casts = [
        'metric_definition_id' => 'integer',
        'dimension' => 'string',
        'source_field' => 'string',
        'values' => 'array',
        'is_default' => 'boolean',
        'meta' => 'array',
    ];

    public function metricDefinition(): BelongsTo
    {
        return $this->belongsTo(HrMetricDefinition::class, 'metric_definition_id');
    }
}
