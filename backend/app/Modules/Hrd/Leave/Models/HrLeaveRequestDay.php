<?php

namespace App\Modules\Hrd\Leave\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_leave_request_days` (domain Leave).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrLeaveRequestDay extends HrdModel
{
    protected $table = 'hr_leave_request_days';

    protected $guarded = [];
    protected $casts = [
        'date' => 'date:Y-m-d',
        'hours' => 'decimal:2',
        'is_paid' => 'boolean',
        'counted' => 'boolean',
    ];

}
