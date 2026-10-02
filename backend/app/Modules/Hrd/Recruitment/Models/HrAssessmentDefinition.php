<?php

namespace App\Modules\Hrd\Recruitment\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_assessment_definitions` (domain Recruitment).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrAssessmentDefinition extends HrdModel
{
    protected $table = 'hr_assessment_definitions';

    protected $guarded = [];
    protected $casts = [
        'max_score' => 'decimal:2',
        'passing_score' => 'decimal:2',
        'weight' => 'decimal:2',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

}
