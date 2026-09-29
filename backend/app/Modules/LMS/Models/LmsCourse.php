<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\CourseCompletionRule;
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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        // Phase 4 — kelulusan, sertifikat, gamifikasi, ulasan, retake.
        'completion_rule' => CourseCompletionRule::class,
        'completion_min_progress' => 'decimal:2',
        'completion_min_grade' => 'decimal:2',
        'required_final_activity_id' => 'integer',
        'certificate_enabled' => 'boolean',
        'certificate_template_id' => 'integer',
        'certificate_min_progress' => 'decimal:2',
        'certificate_min_grade' => 'decimal:2',
        'certificate_required_competency_id' => 'integer',
        'gamification_enabled' => 'boolean',
        'leaderboard_enabled' => 'boolean',
        'review_enabled' => 'boolean',
        'allow_retake' => 'boolean',
        'max_attempts' => 'integer',
        'rating_average' => 'decimal:2',
        'rating_count' => 'integer',
        'learning_path_enabled' => 'boolean',
        'drip_enabled' => 'boolean',
    ];

    /* ---------------------------------------------------------------------
     | Relasi Phase 4 (§1, §13, §21, §26, §31, §47)
     * ------------------------------------------------------------------- */

    public function learningPaths(): HasMany
    {
        return $this->hasMany(LmsLearningPath::class, 'course_id')->orderBy('sort_order');
    }

    public function dripRules(): HasMany
    {
        return $this->hasMany(LmsDripRule::class, 'course_id');
    }

    public function liveSessions(): HasMany
    {
        return $this->hasMany(LmsLiveSession::class, 'course_id')->orderBy('start_at');
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(LmsLiveSessionRecording::class, 'course_id')->orderByDesc('created_at');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(LmsCertificate::class, 'course_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(LmsCourseReview::class, 'course_id');
    }

    public function interactiveContents(): HasMany
    {
        return $this->hasMany(LmsInteractiveContent::class, 'course_id');
    }

    public function learningEvents(): HasMany
    {
        return $this->hasMany(LmsLearningEvent::class, 'course_id');
    }

    /** Course yang diarsipkan tidak lagi menerima perubahan konten (§49). */
    public function isArchived(): bool
    {
        return $this->status === CourseStatus::ARCHIVED;
    }

    public function isReadOnly(): bool
    {
        return $this->isArchived();
    }

    /** Apakah sertifikat diaktifkan untuk course ini (§21). */
    public function certificateEnabled(): bool
    {
        return (bool) $this->certificate_enabled;
    }

    /** Apakah gamifikasi (XP/badge) aktif — selalu opsional (§28). */
    public function gamificationEnabled(): bool
    {
        return (bool) $this->gamification_enabled;
    }

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

    /** Gradebook course (§2). Phase 3 memakai satu gradebook per course. */
    public function gradebook(): HasOne
    {
        return $this->hasOne(LmsGradebook::class, 'course_id');
    }

    /** Learning outcome course (§26). */
    public function learningOutcomes(): HasMany
    {
        return $this->hasMany(LmsLearningOutcome::class, 'course_id')->orderBy('sort_order');
    }

    /** Kompetensi yang dipetakan ke course ini (§29). */
    public function competencies(): BelongsToMany
    {
        return $this->belongsToMany(
            LmsCompetency::class,
            'lms_course_competencies',
            'course_id',
            'competency_id',
        )->withPivot(['is_required', 'weight'])->withTimestamps();
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_COURSE';
    }
}
