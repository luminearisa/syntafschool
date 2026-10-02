<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Recruitment\Models\HrApplicationStageHistory;
use App\Modules\Hrd\Recruitment\Models\HrCandidateAssessment;
use App\Modules\Hrd\Recruitment\Models\HrCandidateProfile;
use App\Modules\Hrd\Recruitment\Models\HrCandidateScreening;
use App\Modules\Hrd\Recruitment\Models\HrInterviewSchedule;
use App\Modules\Hrd\Recruitment\Models\HrJobOffer;
use App\Modules\Hrd\Recruitment\Models\HrJobOpening;
use App\Modules\Hrd\Recruitment\Models\HrRecruitmentRequisition;

/**
 * Phase 2 — tabel `hr_candidate_applications` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrCandidateApplication extends HrdModel
{
    protected $table = 'hr_candidate_applications';

    protected $guarded = [];
    protected $casts = [
        'applied_at' => 'datetime',
        'stage_entered_at' => 'datetime',
        'rating' => 'decimal:2',
        'screening_score' => 'decimal:2',
        'assessment_score' => 'decimal:2',
        'interview_score' => 'decimal:2',
        'converted_at' => 'datetime',
        'meta' => 'array',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(HrCandidateProfile::class, 'candidate_id');
    }
    public function opening(): BelongsTo
    {
        return $this->belongsTo(HrJobOpening::class, 'opening_id');
    }
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(HrRecruitmentRequisition::class, 'requisition_id');
    }
    public function histories(): HasMany
    {
        return $this->hasMany(HrApplicationStageHistory::class, 'application_id');
    }
    public function screenings(): HasMany
    {
        return $this->hasMany(HrCandidateScreening::class, 'application_id');
    }
    public function interviews(): HasMany
    {
        return $this->hasMany(HrInterviewSchedule::class, 'application_id');
    }
    public function assessments(): HasMany
    {
        return $this->hasMany(HrCandidateAssessment::class, 'application_id');
    }
    public function offer(): HasOne
    {
        return $this->hasOne(HrJobOffer::class, 'application_id');
    }
    public function internalEmployee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'internal_employee_id');
    }
    public function hiredEmployee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'hired_employee_id');
    }
}
