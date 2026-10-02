<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Workforce\Models\HrTimesheetEntry;

/**
 * Phase 2 — tabel `hr_timesheets` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTimesheet extends HrdModel
{
    protected $table = 'hr_timesheets';

    protected $guarded = [];
    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'total_hours' => 'decimal:2',
        'billable_hours' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function entries(): HasMany
    {
        return $this->hasMany(HrTimesheetEntry::class, 'timesheet_id');
    }
}
