<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anggota sebuah halaqah. Menjembatani peserta dengan halaqah tempat ia
 * belajar, serta enrollment-nya agar programnya konsisten.
 */
class TahfizHalaqahMember extends BaseModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_MOVED = 'moved';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_MOVED];

    protected $casts = [
        'joined_at' => 'date:Y-m-d',
        'left_at' => 'date:Y-m-d',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function halaqah(): BelongsTo
    {
        return $this->belongsTo(TahfizHalaqah::class, 'halaqah_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TahfizEnrollment::class, 'enrollment_id');
    }
}
