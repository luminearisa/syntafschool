<?php

namespace App\Modules\Hrd\Benefits\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Benefits\Models\HrBenefitDependent;
use App\Modules\Hrd\Benefits\Models\HrBenefitPlan;
use App\Modules\Hrd\Models\HrEmployee;

/**
 * Phase 2 — tabel `hr_benefit_enrollments` (domain Benefits).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrBenefitEnrollment extends HrdModel
{
    protected $table = 'hr_benefit_enrollments';

    protected $guarded = [];
    protected $casts = [
        'coverage_start' => 'date:Y-m-d',
        'coverage_end' => 'date:Y-m-d',
        'employee_contribution' => 'decimal:2',
        'employer_contribution' => 'decimal:2',
        'elected_at' => 'datetime',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function plan(): BelongsTo
    {
        return $this->belongsTo(HrBenefitPlan::class, 'plan_id');
    }
    public function dependents(): HasMany
    {
        return $this->hasMany(HrBenefitDependent::class, 'enrollment_id');
    }
}
