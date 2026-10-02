<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_overtime_records` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrOvertimeRecord extends HrdModel
{
    protected $table = 'hr_overtime_records';

    protected $guarded = [];
    protected $casts = [
        'date' => 'date:Y-m-d',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'multiplier' => 'decimal:2',
        'rate_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'verified_at' => 'datetime',
        'meta' => 'array',
    ];

    /** Pegawai pemilik baris ini. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
