<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_candidate_screenings` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrCandidateScreening extends HrdModel
{
    protected $table = 'hr_candidate_screenings';

    protected $guarded = [];
    protected $casts = [
        'criteria' => 'array',
        'score' => 'decimal:2',
        'reasons' => 'array',
        'screened_at' => 'datetime',
    ];

}
