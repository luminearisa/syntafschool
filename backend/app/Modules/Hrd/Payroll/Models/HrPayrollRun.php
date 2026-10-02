<?php

namespace App\Modules\Hrd\Payroll\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Payroll\Models\HrPayrollApproval;
use App\Modules\Hrd\Payroll\Models\HrPayrollItem;
use App\Modules\Hrd\Payroll\Models\HrPayrollPeriod;
use App\Modules\Hrd\Payroll\Models\HrPayrollPosting;

/**
 * Phase 2 — tabel `hr_payroll_runs` (domain Payroll).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPayrollRun extends HrdModel
{
    protected $table = 'hr_payroll_runs';

    protected $guarded = [];
    protected $casts = [
        'gross_total' => 'decimal:2',
        'earning_total' => 'decimal:2',
        'deduction_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'benefit_total' => 'decimal:2',
        'net_total' => 'decimal:2',
        'employer_cost_total' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'finalized_at' => 'datetime',
        'posted_at' => 'datetime',
        'variance_snapshot' => 'array',
        'meta' => 'array',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(HrPayrollPeriod::class, 'period_id');
    }
    public function items(): HasMany
    {
        return $this->hasMany(HrPayrollItem::class, 'run_id');
    }
    public function approvals(): HasMany
    {
        return $this->hasMany(HrPayrollApproval::class, 'run_id');
    }
    public function postings(): HasMany
    {
        return $this->hasMany(HrPayrollPosting::class, 'run_id');
    }
}
