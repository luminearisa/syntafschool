<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pengumpulan tugas oleh seorang siswa.
 *
 * Satu siswa hanya boleh punya satu baris per tugas — pengumpulan ulang
 * memperbarui baris yang sudah ada (lihat AssignmentSubmissionController).
 */
class AssignmentSubmission extends BaseModel
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_LATE = 'late';
    public const STATUS_GRADED = 'graded';
    public const STATUS_RETURNED = 'returned';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUBMITTED,
        self::STATUS_LATE,
        self::STATUS_GRADED,
        self::STATUS_RETURNED,
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'score' => 'decimal:2',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function isGraded(): bool
    {
        return $this->status === self::STATUS_GRADED;
    }

    public function hasSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }
}
