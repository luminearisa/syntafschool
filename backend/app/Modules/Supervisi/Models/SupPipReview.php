<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan review berkala pelaksanaan PIP. */
class SupPipReview extends BaseModel
{
    protected $table = 'sup_pip_reviews';

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SupPipPlan::class, 'pip_plan_id');
    }
}
