<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\ReviewStatus;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ulasan course (§31) dengan moderasi.
 */
class LmsCourseReview extends BaseModel
{
    use LogsLmsActivity, ResolvesIdentity, SoftDeletes;

    protected $table = 'lms_course_reviews';

    protected $casts = [
        'rating' => 'integer',
        'status' => ReviewStatus::class,
        'is_verified_completion' => 'boolean',
        'helpful_count' => 'integer',
        'moderated_at' => 'datetime',
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

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::APPROVED->value);
    }

    public function displayName(): string
    {
        return (string) ($this->display_name ?: 'Peserta');
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_COURSE_REVIEW';
    }
}
