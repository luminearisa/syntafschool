<?php

namespace App\Modules\Hrd\Learning\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_training_assessments` (domain Learning).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTrainingAssessment extends HrdModel
{
    protected $table = 'hr_training_assessments';

    protected $guarded = [];
    protected $casts = [
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'assessed_at' => 'datetime',
        'meta' => 'array',
    ];

}
