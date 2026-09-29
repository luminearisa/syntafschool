<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak salin/ impor konten course (§46, §50–§51) beserta statistiknya.
 */
class LmsCourseCopy extends BaseModel
{
    protected $table = 'lms_course_copies';

    protected $casts = [
        'options' => 'array',
        'stats' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'source_course_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'target_course_id');
    }
}
