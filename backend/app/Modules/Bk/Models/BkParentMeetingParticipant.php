<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\CaseParticipantRole;
use App\Modules\Bk\Domain\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Peserta pertemuan orang tua beserta kehadirannya.
 *
 * Tabel: `bk_parent_meeting_participants`
 */
class BkParentMeetingParticipant extends BkModel
{
    protected $table = 'bk_parent_meeting_participants';

    protected $casts = [
        'role' => \App\Modules\Bk\Domain\Enums\CaseParticipantRole::class,
        'attendance_status' => \App\Modules\Bk\Domain\Enums\AttendanceStatus::class,
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(BkParentMeeting::class, 'meeting_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
