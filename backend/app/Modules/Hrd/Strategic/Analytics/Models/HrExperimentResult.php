<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Analytics\Models\HrExperiment;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrExperimentResult extends Model
{
    protected $table = 'hr_experiment_results';

    protected $guarded = [];

    protected $casts = [
        'experiment_id' => 'integer',
        'metric_code' => 'string',
        'baseline_value' => 'decimal:2',
        'intervention_value' => 'decimal:2',
        'delta' => 'decimal:2',
        'delta_percent' => 'decimal:2',
        'cohort_size' => 'integer',
        'significance_note' => 'string',
        'evidence' => 'array',
        'measured_at' => 'datetime',
        'meta' => 'array',
    ];

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(HrExperiment::class, 'experiment_id');
    }
}
