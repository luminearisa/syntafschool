<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\GradingType;
use App\Modules\LMS\Domain\Enums\SubmissionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Konfigurasi tugas (§4). */
class LmsAssignment extends BaseModel
{
    protected $table = 'lms_assignments';

    protected $casts = [
        'submission_type' => SubmissionType::class,
        'grading_type' => GradingType::class,
        'maximum_grade' => 'decimal:2',
        'passing_grade' => 'decimal:2',
        'late_penalty_percent' => 'decimal:2',
        'late_penalty_max_percent' => 'decimal:2',
        'maximum_attempts' => 'integer',
        'allow_late_submission' => 'boolean',
        'group_submission' => 'boolean',
        'require_file' => 'boolean',
        'require_text' => 'boolean',
        'allowed_file_types' => 'array',
        'max_file_size_kb' => 'integer',
        'settings' => 'array',
    ];

    /**
     * Ekstensi berkas yang diizinkan (§4). Bila guru tidak menentukan, seluruh
     * daftar di bawah ini berlaku.
     *
     * @return list<string>
     */
    public const DEFAULT_FILE_TYPES = ['PDF', 'DOC', 'DOCX', 'PPT', 'PPTX', 'XLS', 'XLSX', 'ZIP', 'IMAGE'];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(LmsAssignmentSubmission::class, 'assignment_id');
    }

    /** @return list<string> */
    public function allowedFileTypes(): array
    {
        $types = $this->allowed_file_types;

        return empty($types) ? self::DEFAULT_FILE_TYPES : array_values($types);
    }

    public function maxAttempts(): int
    {
        return max(1, (int) $this->maximum_attempts);
    }

    /** Sub-tipe memakai `course_id` milik aktivitas agar authorization seragam. */
    public function getCourseIdAttribute(): ?int
    {
        return $this->activity?->course_id;
    }
}
