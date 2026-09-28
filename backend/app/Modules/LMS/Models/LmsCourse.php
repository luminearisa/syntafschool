<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\CourseLevel;
use App\Modules\LMS\Domain\Enums\CourseStatus;
use App\Modules\LMS\Domain\Enums\CourseType;
use App\Modules\LMS\Domain\Enums\CourseVisibility;
use App\Modules\LMS\Domain\Enums\EnrollmentMethod;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Course adalah inti LMS (§5). */
class LmsCourse extends BaseModel
{
    use LogsLmsActivity, SoftDeletes;

    protected $table = 'lms_courses';

    protected $casts = [
        'course_type' => CourseType::class,
        'status' => CourseStatus::class,
        'visibility' => CourseVisibility::class,
        'level' => CourseLevel::class,
        'enrollment_method' => EnrollmentMethod::class,
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
        'is_featured' => 'boolean',
        'max_students' => 'integer',
        'estimated_minutes' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(LmsCategory::class, 'category_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(LmsCourseSection::class, 'course_id')->orderBy('sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LmsLearningItem::class, 'course_id');
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(LmsCourseTeacher::class, 'course_id')->orderByDesc('is_primary');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(LmsEnrollment::class, 'course_id');
    }

    public function students(): HasMany
    {
        return $this->enrollments()->where('role', EnrollmentRole::STUDENT->value);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(LmsGroup::class, 'course_id')->orderBy('sort_order');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(LmsCourseAnnouncement::class, 'course_id');
    }

    public function progressRecords(): HasMany
    {
        return $this->hasMany(LmsCourseProgress::class, 'course_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::PUBLISHED->value)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->published()->where('visibility', CourseVisibility::PUBLIC->value);
    }

    public function scopeInstitution(Builder $query): Builder
    {
        return $query->published()->whereIn('visibility', [
            CourseVisibility::PUBLIC->value,
            CourseVisibility::INSTITUTION->value,
        ]);
    }

    public function isPublished(): bool
    {
        return $this->status === CourseStatus::PUBLISHED;
    }

    public function isAcademic(): bool
    {
        return $this->course_type === CourseType::ACADEMIC;
    }

    public function isLinkedToAcademic(): bool
    {
        return $this->academic_course_id !== null;
    }

    public function allowsSelfEnrollment(): bool
    {
        return $this->visibility?->isSelfEnrollable() ?? false;
    }

    public function requiredItemCount(): int
    {
        return (int) $this->items()->where('is_required', true)->count();
    }

    public function enrollmentFor(int $userId): ?LmsEnrollment
    {
        return $this->enrollments()->where('user_id', $userId)->first();
    }

    public function activeEnrollmentFor(int $userId): ?LmsEnrollment
    {
        return $this->enrollments()
            ->where('user_id', $userId)
            ->whereIn('status', [
                EnrollmentStatus::ACTIVE->value,
                EnrollmentStatus::COMPLETED->value,
            ])
            ->first();
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_COURSE';
    }
}
