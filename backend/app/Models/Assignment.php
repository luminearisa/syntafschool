<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tugas / penilaian terstruktur yang diberikan guru kepada sebuah kelas.
 *
 * Satu tugas terikat pada satu kelas, satu mapel, satu guru, dan satu semester.
 * Pengumpulan siswa disimpan pada `AssignmentSubmission` (satu baris per siswa).
 */
class Assignment extends BaseModel
{
    use SoftDeletes;

    public const TYPE_TUGAS = 'tugas';
    public const TYPE_KUIS = 'kuis';
    public const TYPE_PROJEK = 'projek';
    public const TYPE_PRAKTIK = 'praktik';
    public const TYPE_PORTOFOLIO = 'portofolio';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_TUGAS,
        self::TYPE_KUIS,
        self::TYPE_PROJEK,
        self::TYPE_PRAKTIK,
        self::TYPE_PORTOFOLIO,
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_CLOSED,
    ];

    protected $casts = [
        'max_score' => 'decimal:2',
        'weight' => 'decimal:2',
        'assigned_date' => 'date',
        'due_date' => 'date',
        'allow_late_submission' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'teacher_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * Tugas yang masih bisa dikumpulkan: sudah dipublikasikan dan belum ditutup.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Batas waktu (akhir hari `due_date`) sudah terlewat.
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->endOfDay()->isPast();
    }

    /**
     * Apakah sebuah waktu pengumpulan melewati batas `due_date`.
     */
    public function isLateAt(CarbonInterface $submittedAt): bool
    {
        return $this->due_date !== null
            && $submittedAt->copy()->startOfDay()->gt($this->due_date->copy()->startOfDay());
    }
}
