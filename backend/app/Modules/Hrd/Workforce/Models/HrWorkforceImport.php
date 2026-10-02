<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_workforce_imports` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrWorkforceImport extends HrdModel
{
    protected $table = 'hr_workforce_imports';

    protected $guarded = [];
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'errors' => 'array',
        'meta' => 'array',
    ];

}
