<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_pipeline_stages` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPipelineStage extends HrdModel
{
    protected $table = 'hr_pipeline_stages';

    protected $guarded = [];
    protected $casts = [
        'is_terminal' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

}
