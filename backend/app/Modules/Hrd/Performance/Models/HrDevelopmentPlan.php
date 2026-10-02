<?php

namespace App\Modules\Hrd\Performance\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Performance\Models\HrDevelopmentPlanAction;

/**
 * Phase 2 — tabel `hr_development_plans` (domain Performance).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrDevelopmentPlan extends HrdModel
{
    protected $table = 'hr_development_plans';

    protected $guarded = [];
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'target_date' => 'date:Y-m-d',
        'progress' => 'decimal:2',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function actions(): HasMany
    {
        return $this->hasMany(HrDevelopmentPlanAction::class, 'plan_id');
    }
}
