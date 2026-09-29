<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\CompetencyStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Status kompetensi seorang siswa (§30–§31).
 *
 * Kompetensi tidak sama dengan nilai: guru menetapkan status berdasarkan
 * evidence, dan sistem tidak menurunkannya otomatis dari angka.
 */
class LmsStudentCompetency extends BaseModel
{
    protected $table = 'lms_student_competencies';

    protected $casts = [
        'status' => CompetencyStatus::class,
        'score' => 'decimal:2',
        'assessed_at' => 'datetime',
    ];

    public function competency(): BelongsTo
    {
        return $this->belongsTo(LmsCompetency::class, 'competency_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(LmsCompetencyEvidence::class, 'student_competency_id');
    }

    public function isAchieved(): bool
    {
        return $this->status->isAchieved();
    }
}
