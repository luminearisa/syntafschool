<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Recruitment\Models\HrInterviewSchedule;
use App\Modules\Hrd\Recruitment\Models\HrInterviewScorecardScore;

/**
 * Phase 2 — tabel `hr_interview_scorecards` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrInterviewScorecard extends HrdModel
{
    protected $table = 'hr_interview_scorecards';

    protected $guarded = [];
    protected $casts = [
        'overall_score' => 'decimal:2',
        'submitted_at' => 'datetime',
        'is_anonymous' => 'boolean',
        'meta' => 'array',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(HrInterviewSchedule::class, 'schedule_id');
    }
    public function scores(): HasMany
    {
        return $this->hasMany(HrInterviewScorecardScore::class, 'scorecard_id');
    }
}
