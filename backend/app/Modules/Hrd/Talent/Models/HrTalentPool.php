<?php

namespace App\Modules\Hrd\Talent\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Talent\Models\HrTalentPoolMember;

/**
 * Phase 2 — tabel `hr_talent_pools` (domain Talent).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTalentPool extends HrdModel
{
    protected $table = 'hr_talent_pools';

    protected $guarded = [];
    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(HrTalentPoolMember::class, 'pool_id');
    }
}
