<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Recruitment\Models\HrCandidateApplication;
use App\Modules\Hrd\Recruitment\Models\HrInterviewInterviewer;
use App\Modules\Hrd\Recruitment\Models\HrInterviewScorecard;

/**
 * Phase 2 — tabel `hr_interview_schedules` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrInterviewSchedule extends HrdModel
{
    protected $table = 'hr_interview_schedules';

    protected $guarded = [];
    protected $casts = [
        'scheduled_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'meta' => 'array',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(HrCandidateApplication::class, 'application_id');
    }
    public function interviewers(): HasMany
    {
        return $this->hasMany(HrInterviewInterviewer::class, 'schedule_id');
    }
    public function scorecards(): HasMany
    {
        return $this->hasMany(HrInterviewScorecard::class, 'schedule_id');
    }
}
