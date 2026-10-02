<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Performance\Models\HrCompetencyLevel;

/**
 * Phase 2 — tabel `hr_competency_catalog` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrCompetencyCatalog extends HrdModel
{
    protected $table = 'hr_competency_catalog';

    protected $guarded = [];
    protected $casts = [
        'weight' => 'decimal:2',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function levels(): HasMany
    {
        return $this->hasMany(HrCompetencyLevel::class, 'competency_id');
    }
}
