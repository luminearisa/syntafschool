<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prasyarat antar-course (§18). */
class LmsCoursePrerequisite extends BaseModel
{
    protected $table = 'lms_course_prerequisites';

    protected $casts = [
        'is_enforced' => 'boolean',
        'minimum_progress' => 'float',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'prerequisite_course_id');
    }
}
