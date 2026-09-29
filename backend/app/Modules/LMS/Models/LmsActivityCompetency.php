<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pemetaan kompetensi → aktivitas (§29). */
class LmsActivityCompetency extends BaseModel
{
    protected $table = 'lms_activity_competencies';

    protected $casts = [
        'weight' => 'decimal:2',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(LmsCompetency::class, 'competency_id');
    }
}
