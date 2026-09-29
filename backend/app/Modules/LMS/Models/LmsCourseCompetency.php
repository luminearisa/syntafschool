<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pemetaan kompetensi → course (§29). */
class LmsCourseCompetency extends BaseModel
{
    protected $table = 'lms_course_competencies';

    protected $casts = [
        'is_required' => 'boolean',
        'weight' => 'decimal:2',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(LmsCompetency::class, 'competency_id');
    }
}
