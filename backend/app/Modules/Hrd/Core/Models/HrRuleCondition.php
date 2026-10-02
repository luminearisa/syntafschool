<?php

namespace App\Modules\Hrd\Core\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_rule_conditions` (domain Core).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrRuleCondition extends HrdModel
{
    protected $table = 'hr_rule_conditions';

    protected $guarded = [];
    protected $casts = [
        'value' => 'array',
        'meta' => 'array',
    ];

}
