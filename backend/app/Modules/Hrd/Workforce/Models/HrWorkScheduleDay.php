<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_work_schedule_days` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrWorkScheduleDay extends HrdModel
{
    protected $table = 'hr_work_schedule_days';

    protected $guarded = [];
    protected $casts = [
        'is_working' => 'boolean',
        'hours' => 'decimal:2',
    ];

}
