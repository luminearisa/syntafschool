<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\CompetencyEvidenceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bukti capaian kompetensi (§31): aktivitas, hasil penilaian, rubric, guru,
 * dan tanggal — sehingga klaim "kompeten" selalu dapat dipertanggungjawabkan.
 */
class LmsCompetencyEvidence extends BaseModel
{
    protected $table = 'lms_competency_evidence';

    protected $casts = [
        'evidence_type' => CompetencyEvidenceType::class,
        'score' => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function competency(): BelongsTo
    {
        return $this->belongsTo(LmsCompetency::class, 'competency_id');
    }

    public function studentCompetency(): BelongsTo
    {
        return $this->belongsTo(LmsStudentCompetency::class, 'student_competency_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function gradeItem(): BelongsTo
    {
        return $this->belongsTo(LmsGradeItem::class, 'grade_item_id');
    }

    public function assessmentResult(): BelongsTo
    {
        return $this->belongsTo(LmsAssessmentResult::class, 'assessment_result_id');
    }
}
