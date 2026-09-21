<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * KKM / KKTP — batas nilai yang tergolong tuntas.
 *
 * Aturan bisa dibuat per mata pelajaran, per jenjang, atau per tahun ajaran.
 * Kolom yang `NULL` berarti "berlaku umum" untuk dimensi tersebut, sehingga
 * satu aturan khusus mapel mengalahkan aturan jenjang, dan aturan jenjang
 * mengalahkan aturan umum sekolah.
 */
class PassingGradeRule extends BaseModel
{
    use SoftDeletes;

    protected $casts = [
        'min_score' => 'decimal:2',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Aturan paling spesifik yang cocok untuk kombinasi ini.
     */
    public static function resolveFor(
        ?int $subjectId = null,
        ?int $educationLevelId = null,
        ?int $academicYearId = null,
    ): ?self {
        $candidates = static::query()
            ->where(function ($q) use ($subjectId) {
                $q->whereNull('subject_id');
                if ($subjectId !== null) {
                    $q->orWhere('subject_id', $subjectId);
                }
            })
            ->where(function ($q) use ($educationLevelId) {
                $q->whereNull('education_level_id');
                if ($educationLevelId !== null) {
                    $q->orWhere('education_level_id', $educationLevelId);
                }
            })
            ->where(function ($q) use ($academicYearId) {
                $q->whereNull('academic_year_id');
                if ($academicYearId !== null) {
                    $q->orWhere('academic_year_id', $academicYearId);
                }
            })
            ->get();

        return $candidates
            ->sort(fn (self $a, self $b) => [$b->specificity(), $a->id] <=> [$a->specificity(), $b->id])
            ->first();
    }

    /**
     * Bobot kekhususan: mapel > jenjang > tahun ajaran.
     */
    public function specificity(): int
    {
        return ($this->subject_id ? 8 : 0)
            + ($this->education_level_id ? 4 : 0)
            + ($this->academic_year_id ? 2 : 0);
    }
}
