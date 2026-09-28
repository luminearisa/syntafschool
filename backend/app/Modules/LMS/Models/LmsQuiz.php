<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\QuizGradeStrategy;
use App\Modules\LMS\Domain\Enums\QuizNavigation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Konfigurasi kuis (§13). */
class LmsQuiz extends BaseModel
{
    protected $table = 'lms_quizzes';

    protected $casts = [
        'time_limit_minutes' => 'integer',
        'attempts_allowed' => 'integer',
        'grade_strategy' => QuizGradeStrategy::class,
        'navigation_method' => QuizNavigation::class,
        'shuffle_questions' => 'boolean',
        'shuffle_answers' => 'boolean',
        'show_one_question_at_time' => 'boolean',
        'require_password' => 'boolean',
        'maximum_grade' => 'decimal:2',
        'passing_grade' => 'decimal:2',
        'questions_per_page' => 'integer',
        'open_at' => 'datetime',
        'close_at' => 'datetime',
        'review_options' => 'array',
        'settings' => 'array',
    ];

    public const REVIEW_DEFAULTS = [
        'show_score' => true,
        'show_correct_answer' => false,
        'show_feedback' => true,
        'show_question' => true,
        'show_answer' => true,
        'show_marks' => true,
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(LmsQuizQuestion::class, 'quiz_id')->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LmsQuizAttempt::class, 'quiz_id');
    }

    /** @return array<string, bool> */
    public function reviewOptions(): array
    {
        return array_merge(self::REVIEW_DEFAULTS, $this->review_options ?? []);
    }

    public function reviewEnabled(string $key): bool
    {
        return (bool) ($this->reviewOptions()[$key] ?? false);
    }

    /** 0 berarti tanpa batas (§30). */
    public function attemptsAllowed(): int
    {
        return max(0, (int) $this->attempts_allowed);
    }

    public function totalMarks(): float
    {
        return (float) $this->questions()->sum('marks');
    }
}
