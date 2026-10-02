<?php

namespace App\Modules\Hrd\Career\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Career\Models\HrCareerPathStep;

/**
 * Phase 2 — tabel `hr_career_paths` (domain Career).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrCareerPath extends HrdModel
{
    protected $table = 'hr_career_paths';

    protected $guarded = [];
    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(HrCareerPathStep::class, 'path_id');
    }
}
