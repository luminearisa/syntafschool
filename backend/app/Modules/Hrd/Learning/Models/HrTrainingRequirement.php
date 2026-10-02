<?php

namespace App\Modules\Hrd\Learning\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_training_requirements` (domain Learning).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTrainingRequirement extends HrdModel
{
    protected $table = 'hr_training_requirements';

    protected $guarded = [];

    /** Pelatihan pada katalog yang diwajibkan oleh baris ini. */
    public function training(): BelongsTo
    {
        return $this->belongsTo(HrTrainingCatalog::class, 'training_id');
    }
    protected $casts = [
        'is_mandatory' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

}
