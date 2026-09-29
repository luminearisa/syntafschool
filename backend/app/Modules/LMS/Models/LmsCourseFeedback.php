<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Umpan balik kualitatif (§32): apa yang disukai & apa yang perlu diperbaiki.
 */
class LmsCourseFeedback extends BaseModel
{
    use ResolvesIdentity;

    protected $table = 'lms_course_feedback';

    protected $casts = [
        'rating' => 'integer',
        'meta' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }
}
