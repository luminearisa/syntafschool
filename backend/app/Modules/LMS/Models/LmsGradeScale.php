<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Skala nilai (§6). Skala tidak pernah di-hard-code: entri A/B/C disimpan di
 * `lms_grade_scale_entries` sehingga sekolah, course, maupun tahun ajaran dapat
 * memakai skala berbeda.
 */
class LmsGradeScale extends BaseModel
{
    protected $table = 'lms_grade_scales';

    protected $casts = [
        'scope' => \App\Modules\LMS\Domain\Enums\GradeScaleScope::class,
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LmsGradeScaleEntry::class, 'grade_scale_id')->orderBy('min_score');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    /** Entri yang cocok untuk sebuah nilai 0–100. */
    public function entryFor(float $score): ?LmsGradeScaleEntry
    {
        return $this->entries
            ->first(fn (LmsGradeScaleEntry $entry) => $entry->matches($score));
    }

    public function letterFor(float $score): ?string
    {
        return $this->entryFor($score)?->grade;
    }

    /**
     * Definisi skala bawaan (§6). Dipakai sebagai fallback bila sekolah belum
     * pernah membuat skala sendiri.
     *
     * @return list<array{grade: string, min_score: float, max_score: float, predicate: string, gpa: float}>
     */
    public static function defaults(): array
    {
        return [
            ['grade' => 'A', 'min_score' => 90, 'max_score' => 100, 'predicate' => 'Sangat Baik', 'gpa' => 4.0],
            ['grade' => 'B', 'min_score' => 80, 'max_score' => 89.99, 'predicate' => 'Baik', 'gpa' => 3.0],
            ['grade' => 'C', 'min_score' => 70, 'max_score' => 79.99, 'predicate' => 'Cukup', 'gpa' => 2.0],
            ['grade' => 'D', 'min_score' => 60, 'max_score' => 69.99, 'predicate' => 'Kurang', 'gpa' => 1.0],
            ['grade' => 'E', 'min_score' => 0, 'max_score' => 59.99, 'predicate' => 'Sangat Kurang', 'gpa' => 0.0],
        ];
    }
}
