<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sanggahan orang tua atas hasil seleksi, dalam masa sanggah periode.
 */
class PpdbRegistrationAppeal extends BaseModel
{
    use LogsPpdbActivity, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_REJECTED,
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(PpdbRegistration::class, 'registration_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PpdbPeriod::class, 'period_id');
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(PpdbParentAccount::class, 'parent_account_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isDecided(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_APPEAL';
    }
}
