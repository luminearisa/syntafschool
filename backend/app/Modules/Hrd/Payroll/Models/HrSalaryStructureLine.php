<?php

namespace App\Modules\Hrd\Payroll\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Payroll\Models\HrSalaryComponent;
use App\Modules\Hrd\Payroll\Models\HrSalaryStructure;

/**
 * Phase 2 — tabel `hr_salary_structure_lines` (domain Payroll).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrSalaryStructureLine extends HrdModel
{
    protected $table = 'hr_salary_structure_lines';

    protected $guarded = [];
    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'is_optional' => 'boolean',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(HrSalaryComponent::class, 'component_id');
    }
    public function structure(): BelongsTo
    {
        return $this->belongsTo(HrSalaryStructure::class, 'structure_id');
    }
}
