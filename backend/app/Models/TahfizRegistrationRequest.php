<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pengajuan pendaftaran mandiri dari peserta umum.
 *
 * Alur: pendaftaran → review admin → placement test → approval → enrollment.
 * Setelah disetujui, satu baris `TahfizParticipant` dibuat dan ditautkan ke
 * `participant_id`.
 */
class TahfizRegistrationRequest extends BaseModel
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'reviewed_at' => 'datetime',
    ];

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(TahfizProgram::class, 'program_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
