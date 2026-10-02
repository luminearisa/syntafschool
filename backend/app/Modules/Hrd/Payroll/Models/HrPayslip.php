<?php

namespace App\Modules\Hrd\Payroll\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Payroll\Models\HrPayrollRun;

/**
 * Phase 2 — tabel `hr_payslips` (domain Payroll).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPayslip extends HrdModel
{
    protected $table = 'hr_payslips';

    protected $guarded = [];
    protected $casts = [
        'gross_amount' => 'decimal:2',
        'deduction_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'snapshot' => 'array',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function run(): BelongsTo
    {
        return $this->belongsTo(HrPayrollRun::class, 'run_id');
    }
}
