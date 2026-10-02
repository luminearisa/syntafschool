<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Recruitment\Models\HrCandidateApplication;
use App\Modules\Hrd\Recruitment\Models\HrCandidateProfile;
use App\Modules\Hrd\Recruitment\Models\HrOfferApproval;
use App\Modules\Hrd\Models\HrPosition;

/**
 * Phase 2 — tabel `hr_job_offers` (domain Core).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrJobOffer extends HrdModel
{
    protected $table = 'hr_job_offers';

    protected $guarded = [];
    protected $casts = [
        'offered_salary' => 'decimal:2',
        'salary_breakdown' => 'array',
        'start_date' => 'date:Y-m-d',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'responded_at' => 'datetime',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(HrCandidateApplication::class, 'application_id');
    }
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(HrCandidateProfile::class, 'candidate_id');
    }
    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }
    public function approvals(): HasMany
    {
        return $this->hasMany(HrOfferApproval::class, 'offer_id');
    }
}
