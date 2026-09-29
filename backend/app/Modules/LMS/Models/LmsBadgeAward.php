<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\BadgeAwardSource;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat pemberian badge (§27) — bersifat tetap (immutable) setelah tercatat.
 */
class LmsBadgeAward extends BaseModel
{
    use ResolvesIdentity;

    protected $table = 'lms_badge_awards';

    protected $casts = [
        'source' => BadgeAwardSource::class,
        'evidence' => 'array',
        'awarded_at' => 'datetime',
    ];

    public function badge(): BelongsTo
    {
        return $this->belongsTo(LmsBadge::class, 'badge_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    /** Riwayat badge tidak boleh diubah — hanya boleh dibuat/dihapus oleh sistem. */
    public function isImmutable(): bool
    {
        return true;
    }
}
