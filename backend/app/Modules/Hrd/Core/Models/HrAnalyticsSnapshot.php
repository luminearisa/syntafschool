<?php

namespace App\Modules\Hrd\Core\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_analytics_snapshots` (domain Core).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrAnalyticsSnapshot extends HrdModel
{
    protected $table = 'hr_analytics_snapshots';

    protected $guarded = [];
    protected $casts = [
        'snapshot_date' => 'date:Y-m-d',
        'metrics' => 'array',
        'cost_total' => 'decimal:2',
    ];

}
