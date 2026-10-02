<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_development_plan_actions` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrDevelopmentPlanAction extends HrdModel
{
    protected $table = 'hr_development_plan_actions';

    protected $guarded = [];
    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'progress' => 'decimal:2',
        'completed_at' => 'datetime',
        'meta' => 'array',
    ];

}
