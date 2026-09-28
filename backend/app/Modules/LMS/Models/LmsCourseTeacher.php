<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengajar course (§42 lms_course_teachers). */
class LmsCourseTeacher extends BaseModel
{
    use LogsLmsActivity;

    protected $table = 'lms_course_teachers';

    protected $casts = [
        'role' => EnrollmentRole::class,
        'is_primary' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_COURSE_TEACHER';
    }
}
