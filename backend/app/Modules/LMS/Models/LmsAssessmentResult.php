<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\AssessmentResultStatus;
use App\Modules\LMS\Domain\Enums\GradeItemSource;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil penilaian terpusat (§12–§13).
 *
 * Satu baris = satu nilai siswa pada satu grade item. Ini **satu-satunya**
 * entity yang dibaca mesin perhitungan; penulisan hanya lewat
 * `AssessmentResultService` sehingga tidak ada sumber nilai ganda.
 */
class LmsAssessmentResult extends BaseModel
{
    protected $table = 'lms_assessment_results';

    protected $casts = [
        'status' => AssessmentResultStatus::class,
        'source' => GradeItemSource::class,
        'raw_score' => 'decimal:2',
        'maximum_score' => 'decimal:2',
        'normalized_score' => 'decimal:4',
        'final_score' => 'decimal:4',
        'percent' => 'decimal:2',
        'is_exempt' => 'boolean',
        'is_extra_credit' => 'boolean',
        'feedback_meta' => 'array',
        'metadata' => 'array',
        'exempted_at' => 'datetime',
        'graded_at' => 'datetime',
        'returned_at' => 'datetime',
        'released_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(LmsGradebook::class, 'gradebook_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LmsGradeItem::class, 'grade_item_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    /** Jejak ke evidence layer Phase 2 (nilai aktivitas). */
    public function activityGrade(): BelongsTo
    {
        return $this->belongsTo(LmsActivityGrade::class, 'activity_grade_id');
    }

    public function isExempt(): bool
    {
        return $this->is_exempt || $this->status === AssessmentResultStatus::EXEMPT;
    }

    /** Belum ada nilai sama sekali — bukan nol (§9). */
    public function isMissing(): bool
    {
        return ! $this->isExempt() && $this->final_score === null;
    }

    /** Nilai 0–100 hasil normalisasi. */
    public function normalized(): ?float
    {
        if ($this->normalized_score !== null) {
            return (float) $this->normalized_score;
        }

        if ($this->final_score === null || (float) $this->maximum_score <= 0.0) {
            return null;
        }

        return round((float) $this->final_score / (float) $this->maximum_score * 100, 4);
    }

    public function percent(): ?float
    {
        $normalized = $this->normalized();

        return $normalized === null ? null : round($normalized, 2);
    }

    public function passed(): ?bool
    {
        if (! $this->status->hasScore()) {
            return null;
        }

        $item = $this->relationLoaded('item') ? $this->item : $this->item()->first();

        return $this->normalized() !== null
            && $item !== null
            && $this->normalized() >= $item->passingGrade() / max((float) $item->maximum_grade, 0.01) * 100;
    }

    /** Nilai yang boleh dilihat siswa. */
    public function isVisibleToStudent(): bool
    {
        return in_array($this->status, [
            AssessmentResultStatus::RETURNED,
            AssessmentResultStatus::RELEASED,
            AssessmentResultStatus::LOCKED,
        ], true);
    }
}
