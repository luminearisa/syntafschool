<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Presensi pegawai (guru & staf) — masuk/pulang per tanggal.
 */
class EmployeeAttendance extends BaseModel
{
    protected $casts = [
        'date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceStatus(): BelongsTo
    {
        return $this->belongsTo(AttendanceStatus::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeOnDate($query, string $date)
    {
        return $query->whereDate('date', $date);
    }

    public function scopeBetween($query, string $from, string $to)
    {
        return $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
    }
}
