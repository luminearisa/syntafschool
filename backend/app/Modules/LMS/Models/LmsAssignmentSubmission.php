<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\AssignmentSubmissionStatus;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pengumpulan tugas per siswa per attempt (§5). */
class LmsAssignmentSubmission extends BaseModel
{
    use LogsLmsActivity, ResolvesIdentity, SoftDeletes;

    protected $table = 'lms_assignment_submissions';

    protected $casts = [
        'status' => AssignmentSubmissionStatus::class,
        'attempt' => 'integer',
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'returned_at' => 'datetime',
        'grade' => 'decimal:2',
        'original_grade' => 'decimal:2',
        'late_penalty_percent' => 'decimal:2',
        'late' => 'boolean',
        'passed' => 'boolean',
        'metadata' => 'array',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(LmsAssignment::class, 'assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'grader_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(LmsAssignmentSubmissionFile::class, 'submission_id');
    }

    public function submissionFiles(): HasMany
    {
        return $this->files()->where('purpose', 'SUBMISSION');
    }

    public function feedbackFiles(): HasMany
    {
        return $this->files()->where('purpose', 'FEEDBACK');
    }

    public function scopeForAssignment(Builder $query, int $assignmentId): Builder
    {
        return $query->where('assignment_id', $assignmentId);
    }

    public function isSubmitted(): bool
    {
        return $this->status->isSubmitted();
    }

    public function isGraded(): bool
    {
        return $this->status === AssignmentSubmissionStatus::GRADED || $this->graded_at !== null;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /** Apakah pengumpulan ini milik pengguna tersebut (§54). */
    public function isOwnedBy(?int $userId): bool
    {
        return $userId !== null && (int) $this->user_id === $userId;
    }
}
