<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\Analytics\Models\HrExperimentResult;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrExperiment extends Model
{
    protected $table = 'hr_experiments';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'initiative_type' => 'string',
        'population' => 'array',
        'baseline_metrics' => 'array',
        'intervention' => 'string',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'status' => 'string',
        'owner_employee_id' => 'integer',
        'meta' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'owner_employee_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(HrExperimentResult::class, 'experiment_id');
    }
}
