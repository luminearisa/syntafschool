<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_attendance_locations` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrAttendanceLocation extends HrdModel
{
    protected $table = 'hr_attendance_locations';

    protected $guarded = [];
    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'geofence_enabled' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

}
