<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_performance_feedback` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPerformanceFeedback extends HrdModel
{
    protected $table = 'hr_performance_feedback';

    protected $guarded = [];
    protected $casts = [
        'rating' => 'decimal:2',
        'is_anonymous' => 'boolean',
        'given_at' => 'datetime',
        'meta' => 'array',
    ];

    /** Pegawai pemilik baris ini. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
