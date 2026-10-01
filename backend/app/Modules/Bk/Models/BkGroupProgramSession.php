<?php

namespace App\Modules\Bk\Models;

use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sesi program kelompok.
 *
 * Kehadiran sesi ditautkan ke Modul Attendance lewat penunjuk, bukan digandakan:
 * absensi pelajaran tetap milik modulnya sendiri.
 *
 * Tabel: `bk_group_program_sessions`
 */
class BkGroupProgramSession extends BkModel
{
    protected $table = 'bk_group_program_sessions';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'materials' => 'array',
        'attendance_recorded_at' => 'datetime',
        'status' => \App\Modules\Bk\Domain\Enums\SessionStatus::class,
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(BkGroupProgram::class, 'program_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(BkGroupProgramAttendance::class, 'session_id');
    }

    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_user_id');
    }
}
