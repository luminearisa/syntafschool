<?php

namespace App\Modules\Hrd\Payroll\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_salary_components` (domain Payroll).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrSalaryComponent extends HrdModel
{
    protected $table = 'hr_salary_components';

    protected $guarded = [];
    protected $casts = [
        'is_taxable' => 'boolean',
        'is_prorated' => 'boolean',
        'is_fixed' => 'boolean',
        'is_statutory' => 'boolean',
        'default_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

}
