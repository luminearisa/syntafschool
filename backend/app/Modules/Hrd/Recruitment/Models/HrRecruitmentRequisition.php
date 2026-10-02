<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrDepartment;
use App\Modules\Hrd\Models\HrJob;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Recruitment\Models\HrJobOpening;

/**
 * Phase 2 — tabel `hr_recruitment_requisitions` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrRecruitmentRequisition extends HrdModel
{
    protected $table = 'hr_recruitment_requisitions';

    protected $guarded = [];
    protected $casts = [
        'budget' => 'decimal:2',
        'target_hire_date' => 'date:Y-m-d',
        'required_skills' => 'array',
        'required_certifications' => 'array',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }
    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }
    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }
    public function job(): BelongsTo
    {
        return $this->belongsTo(HrJob::class, 'job_id');
    }
    public function openings(): HasMany
    {
        return $this->hasMany(HrJobOpening::class, 'requisition_id');
    }
}
