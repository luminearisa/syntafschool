<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_kpi_definitions` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrKpiDefinition extends HrdModel
{
    protected $table = 'hr_kpi_definitions';

    protected $guarded = [];
    protected $casts = [
        'target_default' => 'decimal:2',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

}
