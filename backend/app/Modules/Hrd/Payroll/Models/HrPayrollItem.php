<?php

namespace App\Modules\Hrd\Payroll\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Payroll\Models\HrPayrollItemComponent;
use App\Modules\Hrd\Payroll\Models\HrPayrollRun;

/**
 * Phase 2 — tabel `hr_payroll_items` (domain Payroll).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPayrollItem extends HrdModel
{
    protected $table = 'hr_payroll_items';

    protected $guarded = [];
    protected $casts = [
        'base_amount' => 'decimal:2',
        'earning_total' => 'decimal:2',
        'deduction_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'benefit_total' => 'decimal:2',
        'overtime_amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'prorated_days' => 'decimal:2',
        'absent_days' => 'decimal:2',
        'unpaid_leave_days' => 'decimal:2',
        'snapshot' => 'array',
        'calculated_at' => 'datetime',
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
    public function components(): HasMany
    {
        return $this->hasMany(HrPayrollItemComponent::class, 'item_id');
    }
}
