<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pemetaan aktivitas → learning outcome (§27). Satu aktivitas bisa banyak LO. */
class LmsActivityOutcome extends BaseModel
{
    protected $table = 'lms_activity_outcomes';

    protected $casts = [
        'weight' => 'decimal:2',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(LmsLearningOutcome::class, 'learning_outcome_id');
    }
}
