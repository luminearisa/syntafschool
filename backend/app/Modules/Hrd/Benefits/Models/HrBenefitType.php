<?php

namespace App\Modules\Hrd\Benefits\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_benefit_types` (domain Benefits).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrBenefitType extends HrdModel
{
    protected $table = 'hr_benefit_types';

    protected $guarded = [];
    protected $casts = [
        'is_statutory' => 'boolean',
        'is_active' => 'boolean',
    ];

}
