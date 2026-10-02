<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Performance\Models\HrPerformanceCycle;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_performance_calibrations` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPerformanceCalibration extends HrdModel
{
    protected $table = 'hr_performance_calibrations';

    protected $guarded = [];
    protected $casts = [
        'scheduled_at' => 'datetime',
        'distribution' => 'array',
        'meta' => 'array',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(HrPerformanceCycle::class, 'cycle_id');
    }
}
