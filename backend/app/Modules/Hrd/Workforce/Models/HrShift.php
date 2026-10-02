<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_shifts` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrShift extends HrdModel
{
    protected $table = 'hr_shifts';

    protected $guarded = [];
    protected $casts = [
        'cross_midnight' => 'boolean',
        'total_hours' => 'decimal:2',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

}
