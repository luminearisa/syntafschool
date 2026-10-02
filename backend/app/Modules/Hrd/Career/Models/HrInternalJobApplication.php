<?php

namespace App\Modules\Hrd\Career\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Recruitment\Models\HrJobOpening;
use App\Modules\Hrd\Models\HrEmployee;

/**
 * Phase 2 — tabel `hr_internal_job_applications` (domain Career).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrInternalJobApplication extends HrdModel
{
    protected $table = 'hr_internal_job_applications';

    protected $guarded = [];
    protected $casts = [
        'manager_notified_at' => 'datetime',
        'readiness_score' => 'decimal:2',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function opening(): BelongsTo
    {
        return $this->belongsTo(HrJobOpening::class, 'opening_id');
    }
}
