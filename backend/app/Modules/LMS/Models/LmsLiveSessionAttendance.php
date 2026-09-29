<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\LiveAttendanceStatus;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kehadiran kelas langsung (§15).
 *
 * `source` menegaskan asal data: hanya provider yang benar-benar mengirim data
 * kehadiran yang boleh ditandai PROVIDER — selebihnya MANUAL.
 */
class LmsLiveSessionAttendance extends BaseModel
{
    use ResolvesIdentity;

    public const SOURCE_MANUAL = 'MANUAL';
    public const SOURCE_SELF = 'SELF';
    public const SOURCE_PROVIDER = 'PROVIDER';

    protected $table = 'lms_live_session_attendance';

    protected $casts = [
        'status' => LiveAttendanceStatus::class,
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'minutes_attended' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(LmsLiveSession::class, 'live_session_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }
}
