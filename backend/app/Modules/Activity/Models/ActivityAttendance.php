<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\AttendanceSource;
use App\Modules\Activity\Domain\Enums\AttendanceStatus;

/**
 * Kehadiran per siswa per sesi — unik dan idempoten terhadap pengiriman ulang.
 *
 * Tabel: `activity_attendance`
 */
class ActivityAttendance extends ActivityModel
{
    protected $table = 'activity_attendance';

    protected $casts = [
        'status' => AttendanceStatus::class,
        'source' => AttendanceSource::class,
        'recorded_at' => 'datetime',
        'minutes_late' => 'integer',
    ];
    public function session(): BelongsTo
    {
        return $this->belongsTo(ActivitySession::class, 'session_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function membership(): BelongsTo
    {
        return $this->belongsTo(ActivityMembership::class, 'membership_id');
    }
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'recorded_by');
    }
}
