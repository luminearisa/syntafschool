<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\RubricLevel;

/**
 * Hasil penilaian satu siswa pada satu penilaian — unik dan berumpan balik.
 *
 * Tabel: `activity_assessment_results`
 */
class ActivityAssessmentResult extends ActivityModel
{
    protected $table = 'activity_assessment_results';

    protected $casts = [
        'score' => 'decimal:2',
        'level' => RubricLevel::class,
        'criteria_scores' => 'array',
        'assessed_at' => 'datetime',
    ];
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(ActivityAssessment::class, 'assessment_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function membership(): BelongsTo
    {
        return $this->belongsTo(ActivityMembership::class, 'membership_id');
    }
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assessed_by');
    }
}
