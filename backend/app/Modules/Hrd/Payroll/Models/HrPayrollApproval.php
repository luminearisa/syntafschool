<?php

namespace App\Modules\Hrd\Payroll\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_payroll_approvals` (domain Payroll).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPayrollApproval extends HrdModel
{
    protected $table = 'hr_payroll_approvals';

    protected $guarded = [];
    protected $casts = [
        'decided_at' => 'datetime',
        'meta' => 'array',
    ];

}
