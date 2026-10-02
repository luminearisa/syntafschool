<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_scorecard_template_items` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrScorecardTemplateItem extends HrdModel
{
    protected $table = 'hr_scorecard_template_items';

    protected $guarded = [];
    protected $casts = [
        'weight' => 'decimal:2',
        'max_score' => 'decimal:2',
    ];

}
