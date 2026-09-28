<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\QuizAttemptStatus;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Percobaan kuis (§25). Timer selalu dihitung server (§27). */
class LmsQuizAttempt extends BaseModel
{
    use LogsLmsActivity, ResolvesIdentity;

    protected $table = 'lms_quiz_attempts';

    protected $casts = [
        'status' => QuizAttemptStatus::class,
        'attempt_number' => 'integer',
        'time_limit_minutes' => 'integer',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'raw_score' => 'decimal:2',
        'maximum_score' => 'decimal:2',
        'final_grade' => 'decimal:2',
        'passed' => 'boolean',
        'needs_manual_grading' => 'boolean',
        'metadata' => 'array',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(LmsQuiz::class, 'quiz_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(LmsQuizAnswer::class, 'attempt_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(LmsQuizAttemptQuestion::class, 'attempt_id')->orderBy('sort_order');
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', QuizAttemptStatus::IN_PROGRESS->value);
    }

    public function isInProgress(): bool
    {
        return $this->status === QuizAttemptStatus::IN_PROGRESS;
    }

    /** Sisa waktu menurut server — bukan penghitung JavaScript (§27). */
    public function secondsRemaining(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return max(0, now()->diffInSeconds($this->expires_at, false));
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
