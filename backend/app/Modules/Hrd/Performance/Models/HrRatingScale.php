<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_rating_scales` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrRatingScale extends HrdModel
{
    protected $table = 'hr_rating_scales';

    protected $guarded = [];
    protected $casts = [
        'min_value' => 'decimal:2',
        'max_value' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

}
