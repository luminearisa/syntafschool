<?php

namespace App\Modules\Hrd\Talent\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Talent\Models\HrTalentPool;

/**
 * Phase 2 — tabel `hr_talent_pool_members` (domain Talent).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTalentPoolMember extends HrdModel
{
    protected $table = 'hr_talent_pool_members';

    protected $guarded = [];
    protected $casts = [
        'added_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function pool(): BelongsTo
    {
        return $this->belongsTo(HrTalentPool::class, 'pool_id');
    }
}
