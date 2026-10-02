<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Performance\Models\HrCompetencyCatalog;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_performance_competency_assessments` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPerformanceCompetencyAssessment extends HrdModel
{
    protected $table = 'hr_performance_competency_assessments';

    protected $guarded = [];
    protected $casts = [
        'assessed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function competency(): BelongsTo
    {
        return $this->belongsTo(HrCompetencyCatalog::class, 'competency_id');
    }

    /** Pegawai pemilik baris ini. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
