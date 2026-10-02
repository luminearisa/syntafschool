<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Workforce\Models\HrShift;
use App\Modules\Hrd\Workforce\Models\HrWorkSchedule;

/**
 * Phase 2 — tabel `hr_employee_schedules` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrEmployeeSchedule extends HrdModel
{
    protected $table = 'hr_employee_schedules';

    protected $guarded = [];
    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'weekday_overrides' => 'array',
        'is_primary' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(HrWorkSchedule::class, 'schedule_id');
    }
    public function shift(): BelongsTo
    {
        return $this->belongsTo(HrShift::class, 'shift_id');
    }
}
