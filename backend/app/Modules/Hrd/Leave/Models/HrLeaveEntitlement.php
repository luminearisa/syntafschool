<?php

namespace App\Modules\Hrd\Leave\Models;

use App\Modules\Hrd\Models\HrdModel;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_leave_entitlements` (domain Leave).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrLeaveEntitlement extends HrdModel
{
    protected $table = 'hr_leave_entitlements';

    protected $guarded = [];
    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'entitled_days' => 'decimal:2',
        'accrual_rate' => 'decimal:2',
        'computed_at' => 'datetime',
        'meta' => 'array',
    ];

    /** Pegawai pemilik baris ini. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
