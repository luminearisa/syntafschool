<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Recruitment\Models\HrCandidateApplication;
use App\Modules\Hrd\Recruitment\Models\HrRecruitmentRequisition;

/**
 * Phase 2 — tabel `hr_job_openings` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrJobOpening extends HrdModel
{
    protected $table = 'hr_job_openings';

    protected $guarded = [];
    protected $casts = [
        'channels' => 'array',
        'published_at' => 'datetime',
        'closing_at' => 'datetime',
        'show_salary' => 'boolean',
        'salary_min' => 'decimal:2',
        'salary_max' => 'decimal:2',
        'meta' => 'array',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(HrRecruitmentRequisition::class, 'requisition_id');
    }
    public function applications(): HasMany
    {
        return $this->hasMany(HrCandidateApplication::class, 'opening_id');
    }
}
