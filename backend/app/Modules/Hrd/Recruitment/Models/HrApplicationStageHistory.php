<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Hrd\Recruitment\Models\HrCandidateApplication;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_application_stage_histories` (domain Core).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrApplicationStageHistory extends HrdModel
{
    protected $table = 'hr_application_stage_histories';

    protected $guarded = [];
    protected $casts = [
        'moved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(HrCandidateApplication::class, 'application_id');
    }
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
