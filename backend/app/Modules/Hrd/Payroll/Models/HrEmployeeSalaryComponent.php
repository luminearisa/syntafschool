<?php

namespace App\Modules\Hrd\Payroll\Models;

use App\Modules\Hrd\Models\HrdModel;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Payroll\Models\HrSalaryComponent;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_employee_salary_components` (domain Payroll).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrEmployeeSalaryComponent extends HrdModel
{
    protected $table = 'hr_employee_salary_components';

    protected $guarded = [];
    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(HrSalaryComponent::class, 'component_id');
    }

    /** Pegawai pemilik baris ini. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
