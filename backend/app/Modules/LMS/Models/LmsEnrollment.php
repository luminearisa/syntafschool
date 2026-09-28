<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\EnrollmentMethod;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Keikutsertaan seorang pelaku di sebuah course (§15). */
class LmsEnrollment extends BaseModel
{
    use LogsLmsActivity, ResolvesIdentity, SoftDeletes;

    protected $table = 'lms_enrollments';

    protected $casts = [
        'role' => EnrollmentRole::class,
        'status' => EnrollmentStatus::class,
        'enrollment_method' => EnrollmentMethod::class,
        'enrolled_at' => 'datetime',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'suspended_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'progress_percent' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    public function progress(): HasOne
    {
        return $this->hasOne(LmsCourseProgress::class, 'enrollment_id');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(LmsActivityCompletion::class, 'enrollment_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::ACTIVE->value);
    }

    public function scopeStudents(Builder $query): Builder
    {
        return $query->where('role', EnrollmentRole::STUDENT->value);
    }

    public function isActive(): bool
    {
        return $this->status?->grantsAccess() ?? false;
    }
}
