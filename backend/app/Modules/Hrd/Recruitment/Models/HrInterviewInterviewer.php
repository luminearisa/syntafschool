<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_interview_interviewers` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrInterviewInterviewer extends HrdModel
{
    protected $table = 'hr_interview_interviewers';

    protected $guarded = [];
    protected $casts = [
        'is_lead' => 'boolean',
        'notified_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

}
