<?php

namespace App\Modules\Hrd\Payroll\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Payroll\Models\HrPayrollRun;

/**
 * Phase 2 — tabel `hr_payroll_periods` (domain Payroll).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPayrollPeriod extends HrdModel
{
    protected $table = 'hr_payroll_periods';

    protected $guarded = [];
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'pay_date' => 'date:Y-m-d',
        'cutoff_date' => 'date:Y-m-d',
        'is_locked' => 'boolean',
        'meta' => 'array',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(HrPayrollRun::class, 'period_id');
    }
}
