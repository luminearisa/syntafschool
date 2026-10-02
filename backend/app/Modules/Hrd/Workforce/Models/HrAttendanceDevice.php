<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_attendance_devices` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrAttendanceDevice extends HrdModel
{
    protected $table = 'hr_attendance_devices';

    protected $guarded = [];
    protected $casts = [
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
        'config' => 'array',
        'meta' => 'array',
    ];

}
