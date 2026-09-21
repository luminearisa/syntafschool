<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notifikasi modul Tahfiz per peran.
 *
 * Isi pesan dibawa di `type` + `data`, bukan ditulis keras di kode, agar
 * pemberitahuan (setoran dinilai, target tertinggal, ujian dijadwalkan,
 * capaian baru) dapat berkembang tanpa mengubah skema.
 */
class TahfizNotification extends BaseModel
{
    public const AUDIENCE_PARTICIPANT = 'participant';
    public const AUDIENCE_INSTRUCTOR = 'instructor';
    public const AUDIENCE_MUSYRIF = 'musyrif';
    public const AUDIENCE_PARENT = 'parent';
    public const AUDIENCE_ADMIN = 'admin';

    /** @var list<string> */
    public const AUDIENCES = [
        self::AUDIENCE_PARTICIPANT, self::AUDIENCE_INSTRUCTOR,
        self::AUDIENCE_MUSYRIF, self::AUDIENCE_PARENT, self::AUDIENCE_ADMIN,
    ];

    public const TYPE_SETORAN_REVIEWED = 'setoran_reviewed';
    public const TYPE_SETORAN_REVISION = 'setoran_revision';
    public const TYPE_TARGET_OVERDUE = 'target_overdue';
    public const TYPE_EXAM_SCHEDULED = 'exam_scheduled';
    public const TYPE_EXAM_RESULT = 'exam_result';
    public const TYPE_CERTIFICATE_ISSUED = 'certificate_issued';
    public const TYPE_ENROLLMENT_APPROVED = 'enrollment_approved';
    public const TYPE_ENROLLMENT_PENDING = 'enrollment_pending';
    public const TYPE_NOT_SUBMITTED = 'not_submitted';
    public const TYPE_ACHIEVEMENT = 'achievement';
    public const TYPE_INTERVENTION = 'intervention';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_SETORAN_REVIEWED, self::TYPE_SETORAN_REVISION,
        self::TYPE_TARGET_OVERDUE, self::TYPE_EXAM_SCHEDULED,
        self::TYPE_EXAM_RESULT, self::TYPE_CERTIFICATE_ISSUED,
        self::TYPE_ENROLLMENT_APPROVED, self::TYPE_ENROLLMENT_PENDING,
        self::TYPE_NOT_SUBMITTED, self::TYPE_ACHIEVEMENT, self::TYPE_INTERVENTION,
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function markAsRead(): bool
    {
        return $this->update(['read_at' => now()]);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
