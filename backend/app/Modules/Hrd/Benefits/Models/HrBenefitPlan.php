<?php

namespace App\Modules\Hrd\Benefits\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Benefits\Models\HrBenefitEnrollment;
use App\Modules\Hrd\Benefits\Models\HrBenefitType;

/**
 * Phase 2 — tabel `hr_benefit_plans` (domain Benefits).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrBenefitPlan extends HrdModel
{
    protected $table = 'hr_benefit_plans';

    protected $guarded = [];
    protected $casts = [
        'employee_contribution' => 'decimal:2',
        'employer_contribution' => 'decimal:2',
        'requires_dependents' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function benefitType(): BelongsTo
    {
        return $this->belongsTo(HrBenefitType::class, 'benefit_type_id');
    }
    public function enrollments(): HasMany
    {
        return $this->hasMany(HrBenefitEnrollment::class, 'plan_id');
    }
}
