<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Performance\Models\HrPerformanceCycle;

/**
 * Phase 2 — tabel `hr_performance_reviews` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrPerformanceReview extends HrdModel
{
    protected $table = 'hr_performance_reviews';

    protected $guarded = [];
    protected $casts = [
        'goal_score' => 'decimal:2',
        'competency_score' => 'decimal:2',
        'overall_score' => 'decimal:2',
        'submitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'finalized_at' => 'datetime',
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
