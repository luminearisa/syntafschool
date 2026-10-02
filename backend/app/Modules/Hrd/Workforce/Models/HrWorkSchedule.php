<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Workforce\Models\HrWorkScheduleDay;

/**
 * Phase 2 — tabel `hr_work_schedules` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrWorkSchedule extends HrdModel
{
    protected $table = 'hr_work_schedules';

    protected $guarded = [];
    protected $casts = [
        'weekly_hours' => 'decimal:2',
        'working_days' => 'array',
        'is_default' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function days(): HasMany
    {
        return $this->hasMany(HrWorkScheduleDay::class, 'schedule_id');
    }
}
