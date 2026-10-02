<?php

namespace App\Modules\Hrd\Core\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Core\Models\HrRuleCondition;

/**
 * Phase 2 — tabel `hr_rules` (domain Core).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrRule extends HrdModel
{
    protected $table = 'hr_rules';

    protected $guarded = [];
    protected $casts = [
        'action_payload' => 'array',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function conditions(): HasMany
    {
        return $this->hasMany(HrRuleCondition::class, 'rule_id');
    }
}
