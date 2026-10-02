<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_candidate_sources` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrCandidateSource extends HrdModel
{
    protected $table = 'hr_candidate_sources';

    protected $guarded = [];
    protected $casts = [
        'cost_per_hire' => 'decimal:2',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

}
