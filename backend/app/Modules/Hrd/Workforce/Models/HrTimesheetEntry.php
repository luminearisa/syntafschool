<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Workforce\Models\HrTimesheet;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_timesheet_entries` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTimesheetEntry extends HrdModel
{
    protected $table = 'hr_timesheet_entries';

    protected $guarded = [];
    protected $casts = [
        'work_date' => 'date:Y-m-d',
        'hours' => 'decimal:2',
        'is_billable' => 'boolean',
        'approved' => 'boolean',
        'meta' => 'array',
    ];

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(HrTimesheet::class, 'timesheet_id');
    }

    /** Pegawai pemilik baris ini. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
