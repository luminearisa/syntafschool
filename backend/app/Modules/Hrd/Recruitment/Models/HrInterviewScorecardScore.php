<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_interview_scorecard_scores` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrInterviewScorecardScore extends HrdModel
{
    protected $table = 'hr_interview_scorecard_scores';

    protected $guarded = [];
    protected $casts = [
        'weight' => 'decimal:2',
        'score' => 'decimal:2',
    ];

}
