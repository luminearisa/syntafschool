<?php

namespace App\Modules\Hrd\Payroll\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Payroll\Models\HrSalaryStructureLine;

/**
 * Phase 2 — tabel `hr_salary_structures` (domain Payroll).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrSalaryStructure extends HrdModel
{
    protected $table = 'hr_salary_structures';

    protected $guarded = [];
    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'base_amount' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(HrSalaryStructureLine::class, 'structure_id');
    }
}
