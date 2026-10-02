<?php

namespace App\Modules\Hrd\Talent\Models;

use App\Modules\Hrd\Models\HrdModel;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_potential_assessments` (domain Talent).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPotentialAssessment extends HrdModel
{
    protected $table = 'hr_potential_assessments';

    protected $guarded = [];
    protected $casts = [
        'potential_score' => 'decimal:2',
        'learning_agility' => 'decimal:2',
        'aspiration' => 'array',
        'mobility' => 'array',
        'assessed_at' => 'datetime',
        'meta' => 'array',
    ];

    /** Pegawai pemilik baris ini. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
