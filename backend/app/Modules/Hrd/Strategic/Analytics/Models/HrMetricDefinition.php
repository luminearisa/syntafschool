<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Analytics\Models\HrMetricDimension;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrMetricDefinition extends Model
{
    protected $table = 'hr_metric_definitions';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'category' => 'string',
        'entity' => 'string',
        'formula_type' => 'string',
        'numerator' => 'array',
        'denominator' => 'array',
        'filters' => 'array',
        'dimensions' => 'array',
        'period_basis' => 'string',
        'unit' => 'string',
        'description' => 'string',
        'owner_role' => 'string',
        'steward_role' => 'string',
        'data_classification' => 'string',
        'refresh_frequency' => 'string',
        'is_canonical' => 'boolean',
        'source_tables' => 'array',
        'sensitivity' => 'string',
        'version' => 'integer',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function dimensions(): HasMany
    {
        return $this->hasMany(HrMetricDimension::class, 'metric_definition_id');
    }
}
