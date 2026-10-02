<?php

namespace App\Modules\Hrd\Learning\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Learning\Models\HrTrainingAssignment;
use App\Modules\Hrd\Learning\Models\HrTrainingRequirement;

/**
 * Phase 2 — tabel `hr_training_catalog` (domain Learning).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTrainingCatalog extends HrdModel
{
    protected $table = 'hr_training_catalog';

    protected $guarded = [];
    protected $casts = [
        'duration_hours' => 'decimal:2',
        'cost' => 'decimal:2',
        'skills' => 'array',
        'competencies' => 'array',
        'is_mandatory' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(HrTrainingAssignment::class, 'training_id');
    }
    public function requirements(): HasMany
    {
        return $this->hasMany(HrTrainingRequirement::class, 'training_id');
    }
}
