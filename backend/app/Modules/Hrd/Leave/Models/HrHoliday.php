<?php

namespace App\Modules\Hrd\Leave\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_holidays` (domain Leave).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrHoliday extends HrdModel
{
    protected $table = 'hr_holidays';

    protected $guarded = [];
    protected $casts = [
        'date' => 'date:Y-m-d',
        'is_paid' => 'boolean',
        'is_recurring' => 'boolean',
    ];

}
