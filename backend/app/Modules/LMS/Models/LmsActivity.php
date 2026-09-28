<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\ActivityStatus;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\ContentVisibility;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aktivitas pembelajaran Phase 2 (§2).
 *
 * Setiap aktivitas selalu menempel pada satu `lms_learning_items`, sehingga
 * otomatis mengikuti course, section, urutan, prasyarat, dan penyelesaian yang
 * sudah dibangun pada Phase 1 — bukan modul terpisah.
 */
class LmsActivity extends BaseModel
{
    use LogsLmsActivity, ResolvesIdentity, SoftDeletes;

    protected $table = 'lms_activities';

    protected $casts = [
        'activity_type' => ActivityType::class,
        'status' => ActivityStatus::class,
        'visibility' => ContentVisibility::class,
        'completion_rule' => CompletionType::class,
        'availability' => 'array',
        'settings' => 'array',
        'is_graded' => 'boolean',
        'start_at' => 'datetime',
        'due_at' => 'datetime',
        'close_at' => 'datetime',
        'published_at' => 'datetime',
        'completion_min_grade' => 'decimal:2',
    ];

    // ------------------------------------------------------------------ relasi

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(LmsCourseSection::class, 'section_id');
    }

    public function learningItem(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'learning_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'created_by');
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(LmsAssignment::class, 'activity_id');
    }

    public function quiz(): HasOne
    {
        return $this->hasOne(LmsQuiz::class, 'activity_id');
    }

    public function forum(): HasOne
    {
        return $this->hasOne(LmsForum::class, 'activity_id');
    }

    public function lesson(): HasOne
    {
        return $this->hasOne(LmsLesson::class, 'activity_id');
    }

    public function choice(): HasOne
    {
        return $this->hasOne(LmsChoice::class, 'activity_id');
    }

    public function survey(): HasOne
    {
        return $this->hasOne(LmsSurvey::class, 'activity_id');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(LmsActivityGrade::class, 'activity_id');
    }

    /** Sub-type configuration sesuai `activity_type`. */
    public function detail(): ?BaseModel
    {
        return match ($this->activity_type) {
            ActivityType::ASSIGNMENT => $this->assignment,
            ActivityType::QUIZ => $this->quiz,
            ActivityType::FORUM => $this->forum,
            ActivityType::LESSON => $this->lesson,
            ActivityType::CHOICE => $this->choice,
            ActivityType::SURVEY => $this->survey,
            default => null,
        };
    }

    // ------------------------------------------------------------------ scope

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ActivityStatus::PUBLISHED->value);
    }

    public function scopeForCourse(Builder $query, int $courseId): Builder
    {
        return $query->where('course_id', $courseId);
    }

    // ------------------------------------------------------------------ bantu

    public function isPublished(): bool
    {
        return $this->status === ActivityStatus::PUBLISHED;
    }

    public function isGraded(): bool
    {
        return (bool) $this->is_graded;
    }

    public function submissionWindowOpen(): bool
    {
        if ($this->start_at !== null && $this->start_at->isFuture()) {
            return false;
        }

        return $this->close_at === null || $this->close_at->isFuture();
    }

    public function isPastDue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast();
    }

    public function grade(BaseModel|int|null $user): ?LmsActivityGrade
    {
        $userId = $user instanceof BaseModel ? $user->getKey() : $user;

        return $userId === null ? null : $this->grades()->where('user_id', $userId)->first();
    }

    /** URL rute frontend untuk membuka aktivitas. */
    public function playerUrl(): string
    {
        return '/lms/activities/'.$this->id;
    }
}
