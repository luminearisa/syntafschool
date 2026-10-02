<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrMetricSnapshot extends Model
{
    protected $table = 'hr_metric_snapshots';

    protected $guarded = [];

    protected $casts = [
        'metric_code' => 'string',
        'period' => 'string',
        'as_of' => 'date:Y-m-d',
        'scope' => 'string',
        'organization_id' => 'integer',
        'dimension_values' => 'array',
        'value' => 'decimal:2',
        'numerator_value' => 'decimal:2',
        'denominator_value' => 'decimal:2',
        'sample_size' => 'integer',
        'trend_ref' => 'array',
        'computed_at' => 'datetime',
        'model_version' => 'string',
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }
}
