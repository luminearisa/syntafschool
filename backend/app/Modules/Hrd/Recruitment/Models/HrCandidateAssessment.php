<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Recruitment\Models\HrAssessmentDefinition;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_candidate_assessments` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrCandidateAssessment extends HrdModel
{
    protected $table = 'hr_candidate_assessments';

    protected $guarded = [];
    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
        'score' => 'decimal:2',
        'criteria_scores' => 'array',
        'meta' => 'array',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(HrAssessmentDefinition::class, 'assessment_id');
    }
}
