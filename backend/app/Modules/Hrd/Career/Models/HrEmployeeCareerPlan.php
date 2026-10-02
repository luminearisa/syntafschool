<?php

namespace App\Modules\Hrd\Career\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;

/**
 * Phase 2 — tabel `hr_employee_career_plans` (domain Career).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrEmployeeCareerPlan extends HrdModel
{
    protected $table = 'hr_employee_career_plans';

    protected $guarded = [];
    protected $casts = [
        'readiness_score' => 'decimal:2',
        'gap_snapshot' => 'array',
        'reviewed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
