<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gelombang PPDB (satu periode boleh punya banyak gelombang, biaya boleh beda).
 */
class PpdbWave extends BaseModel
{
    use \App\Models\Concerns\LogsPpdbActivity;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $casts = [
        'quota' => 'integer',
        'priority' => 'integer',
        'registration_start' => 'date:Y-m-d',
        'registration_end' => 'date:Y-m-d',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PpdbPeriod::class, 'period_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(PpdbRegistration::class, 'wave_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isOpenOn(?string $date = null): bool
    {
        $date ??= now()->toDateString();

        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->registration_start !== null && $this->registration_start->toDateString() > $date) {
            return false;
        }

        return $this->registration_end === null || $this->registration_end->toDateString() >= $date;
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_WAVE';
    }
}