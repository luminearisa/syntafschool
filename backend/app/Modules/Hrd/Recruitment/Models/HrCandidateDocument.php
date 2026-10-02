<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_candidate_documents` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrCandidateDocument extends HrdModel
{
    protected $table = 'hr_candidate_documents';

    protected $guarded = [];
    protected $casts = [
        'expires_at' => 'date:Y-m-d',
        'verified_at' => 'datetime',
        'meta' => 'array',
    ];

}
