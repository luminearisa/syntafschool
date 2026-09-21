<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu penilaian yang dijalankan guru untuk satu kelas pada satu mapel.
 *
 * Semua jenis penilaian (kuis, tugas, praktik, PTS, ...) memakai tabel yang
 * sama; yang berbeda hanya `assessment_type_id` dan aturannya. Yang disimpan di
 * sini adalah ** definisi ** penilaiannya — hasil kerja siswa ada di `scores`.
 *
 * Alur status: draft → submitted → verified → finalized. Setelah `finalized`
 * skor terkunci; perbaikan hanya lewat pengajuan revisi sehingga kembali ke
 * `revision_requested`.
 */
class Assessment extends BaseModel
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_REVISION_REQUESTED = 'revision_requested';

    /** Urutan alur yang sah, dipakai untuk memvalidasi transisi status. */
    public const STATUS_FLOW = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_VERIFIED,
        self::STATUS_FINALIZED,
    ];

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_VERIFIED,
        self::STATUS_FINALIZED,
        self::STATUS_REVISION_REQUESTED,
    ];

    protected $casts = [
        'max_score' => 'decimal:2',
        'weight' => 'decimal:2',
        'date' => 'date',
        'extra' => 'array',
    ];

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function assessmentType(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class);
    }

    public function gradingFormula(): BelongsTo
    {
        return $this->belongsTo(GradingFormula::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(AssessmentComponent::class)->orderBy('id');
    }

    public function rubrics(): HasMany
    {
        return $this->hasMany(AssessmentRubric::class)->orderBy('id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    public function remedials(): HasMany
    {
        return $this->hasMany(Remedial::class);
    }

    public function scopeForContext(
        Builder $query,
        ?int $classId = null,
        ?int $subjectId = null,
        ?int $semesterId = null,
    ): Builder {
        return $query
            ->when($classId, fn (Builder $q) => $q->where('school_class_id', $classId))
            ->when($subjectId, fn (Builder $q) => $q->where('subject_id', $subjectId))
            ->when($semesterId, fn (Builder $q) => $q->where('semester_id', $semesterId));
    }

    /**
     * Nilai maksimum penilaian ini; kolomnya boleh kosong secara historis.
     */
    public function maxScoreValue(): float
    {
        return (float) ($this->max_score ?: 100);
    }

    /**
     * Skor tidak boleh diubah lagi setelah finalisasi.
     */
    public function isLocked(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }
}
