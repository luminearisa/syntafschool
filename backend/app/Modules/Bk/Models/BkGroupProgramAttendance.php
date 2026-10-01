<?php

namespace App\Modules\Bk\Models;

use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kehadiran siswa pada satu sesi program.
 *
 * Sumber barisnya dibedakan (MANUAL atau dari Modul Attendance), sehingga bila
 * kelak ada perbedaan angka, asal-usulnya dapat ditelusuri.
 *
 * Tabel: `bk_group_program_attendance`
 */
class BkGroupProgramAttendance extends BkModel
{
    protected $table = 'bk_group_program_attendance';

    use BelongsToSchool;

    protected $casts = [
        'marked_at' => 'datetime',
        'attendance_status' => \App\Modules\Bk\Domain\Enums\GroupAttendanceStatus::class,
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(BkGroupProgram::class, 'program_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BkGroupProgramSession::class, 'session_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(BkGroupProgramMember::class, 'member_id');
    }
}
