<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Performance\Models\HrPerformanceCycle;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_performance_cycle_participants` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPerformanceCycleParticipant extends HrdModel
{
    protected $table = 'hr_performance_cycle_participants';

    protected $guarded = [];
    protected $casts = [
        'self_review_submitted_at' => 'datetime',
        'manager_review_submitted_at' => 'datetime',
        'final_rating' => 'decimal:2',
        'potential_rating' => 'decimal:2',
        'acknowledged_at' => 'datetime',
        'meta' => 'array',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(HrPerformanceCycle::class, 'cycle_id');
    }
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
