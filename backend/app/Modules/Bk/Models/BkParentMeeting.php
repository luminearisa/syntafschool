<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\ParentMeetingStatus;
use App\Modules\Bk\Domain\Enums\SessionMode;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pertemuan orang tua: permintaan → jadwal → notulen → daftar tindakan → tindak lanjut.
 *
 * Tabel: `bk_parent_meetings`
 */
class BkParentMeeting extends BkModel
{
    protected $table = 'bk_parent_meetings';

    use SoftDeletes;

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'minutes_recorded_at' => 'datetime',
        'next_meeting_on' => 'date',
        'status' => \App\Modules\Bk\Domain\Enums\ParentMeetingStatus::class,
        'mode' => \App\Modules\Bk\Domain\Enums\SessionMode::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }

    public function minutesRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'minutes_recorded_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(BkParentMeetingParticipant::class, 'meeting_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(BkParentMeetingAction::class, 'meeting_id');
    }
}
