<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_attendance_events` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrAttendanceEvent extends HrdModel
{
    protected $table = 'hr_attendance_events';

    protected $guarded = [];
    protected $casts = [
        'occurred_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'accuracy' => 'decimal:2',
        'raw' => 'array',
    ];

    /** Pegawai pemilik baris ini. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
