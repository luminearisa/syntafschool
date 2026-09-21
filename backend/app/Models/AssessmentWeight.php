<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bobot sebuah jenis penilaian untuk satu kelas pada satu tahun ajaran.
 *
 * Ini "assessment scheme" (A7): misalnya kuis 20%, tugas 30%, PTS 25%, PAS 25%.
 * Penilaian individual boleh menimpa bobot jenisnya lewat `assessments.weight`.
 */
class AssessmentWeight extends BaseModel
{
    protected $casts = [
        'weight' => 'decimal:2',
    ];

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function assessmentType(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class);
    }
}
