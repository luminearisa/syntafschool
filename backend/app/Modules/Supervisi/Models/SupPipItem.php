<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Target terukur di dalam PIP. */
class SupPipItem extends BaseModel
{
    protected $table = 'sup_pip_items';

    protected $casts = [
        'target_value' => 'float',
        'due_date' => 'date',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SupPipPlan::class, 'pip_plan_id');
    }
}
