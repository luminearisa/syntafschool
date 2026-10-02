<?php

namespace App\Modules\Hrd\Payroll\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_tax_rules` (domain Payroll).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTaxRule extends HrdModel
{
    protected $table = 'hr_tax_rules';

    protected $guarded = [];
    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'brackets' => 'array',
        'rates' => 'array',
        'employee_rate' => 'decimal:4',
        'employer_rate' => 'decimal:4',
        'is_active' => 'boolean',
    ];

}
