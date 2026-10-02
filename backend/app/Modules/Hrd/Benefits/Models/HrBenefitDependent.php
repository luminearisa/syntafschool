<?php

namespace App\Modules\Hrd\Benefits\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_benefit_dependents` (domain Benefits).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrBenefitDependent extends HrdModel
{
    protected $table = 'hr_benefit_dependents';

    protected $guarded = [];
    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'is_covered' => 'boolean',
        'meta' => 'array',
    ];

}
