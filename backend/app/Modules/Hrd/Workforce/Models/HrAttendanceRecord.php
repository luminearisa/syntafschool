<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Workforce\Models\HrAttendanceEvent;
use App\Modules\Hrd\Workforce\Models\HrAttendanceLocation;
use App\Modules\Hrd\Workforce\Models\HrShift;
use App\Modules\Hrd\Workforce\Models\HrWorkSchedule;

/**
 * Phase 2 — tabel `hr_attendance_records` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrAttendanceRecord extends HrdModel
{
    protected $table = 'hr_attendance_records';

    protected $guarded = [];
    protected $casts = [
        'date' => 'date:Y-m-d',
        'clock_in' => 'datetime',
        'clock_out' => 'datetime',
        'is_holiday' => 'boolean',
        'is_remote' => 'boolean',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function events(): HasMany
    {
        return $this->hasMany(HrAttendanceEvent::class, 'attendance_record_id');
    }
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(HrWorkSchedule::class, 'schedule_id');
    }
    public function shift(): BelongsTo
    {
        return $this->belongsTo(HrShift::class, 'shift_id');
    }
    public function location(): BelongsTo
    {
        return $this->belongsTo(HrAttendanceLocation::class, 'location_id');
    }
}
