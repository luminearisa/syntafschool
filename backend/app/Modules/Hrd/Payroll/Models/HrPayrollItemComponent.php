<?php

namespace App\Modules\Hrd\Payroll\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Payroll\Models\HrSalaryComponent;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_payroll_item_components` (domain Payroll).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPayrollItemComponent extends HrdModel
{
    protected $table = 'hr_payroll_item_components';

    protected $guarded = [];
    protected $casts = [
        'amount' => 'decimal:2',
        'quantity' => 'decimal:2',
        'rate' => 'decimal:2',
        'is_prorated' => 'boolean',
        'is_taxable' => 'boolean',
        'reference' => 'array',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(HrSalaryComponent::class, 'component_id');
    }
}
