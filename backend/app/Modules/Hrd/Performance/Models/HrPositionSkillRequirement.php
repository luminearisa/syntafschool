<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_position_skill_requirements` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPositionSkillRequirement extends HrdModel
{
    protected $table = 'hr_position_skill_requirements';

    protected $guarded = [];
    protected $casts = [
        'weight' => 'decimal:2',
        'is_mandatory' => 'boolean',
        'meta' => 'array',
    ];

}
