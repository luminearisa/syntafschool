<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\GamificationEventType;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger poin/XP (§28). Satu peristiwa = satu baris, dijaga `dedupe_key`
 * sehingga siswa tidak dapat menggandakan XP lewat pemanggilan API berulang.
 */
class LmsGamificationPoint extends BaseModel
{
    use ResolvesIdentity;

    protected $table = 'lms_gamification_points';

    protected $casts = [
        'event_type' => GamificationEventType::class,
        'points' => 'integer',
        'metadata' => 'array',
        'earned_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
