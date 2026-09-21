<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reservasi (antrean) sebuah judul oleh anggota.
 *
 * Alur: `waiting` → `available` (eksemplar tersedia & disiapkan) →
 * `picked_up` → `completed`; bila tidak diambil sebelum `expires_at` menjadi
 * `expired` (ditetapkan scheduler). Posisi antrean dihitung per judul.
 */
class LibraryReservation extends BaseModel
{
    use LogsLibraryActivity, SoftDeletes;

    public const STATUS_WAITING = 'waiting';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_AVAILABLE,
        self::STATUS_PICKED_UP,
        self::STATUS_COMPLETED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    /** Status yang masih menahan antrean. */
    public const OPEN_STATUSES = [self::STATUS_WAITING, self::STATUS_AVAILABLE];

    protected $casts = [
        'queue_position' => 'integer',
        'reserved_at' => 'date:Y-m-d',
        'available_at' => 'datetime',
        'expires_at' => 'date:Y-m-d',
        'picked_up_at' => 'date:Y-m-d',
        'cancelled_at' => 'date:Y-m-d',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'member_id');
    }

    public function bibliography(): BelongsTo
    {
        return $this->belongsTo(LibraryBibliography::class, 'bibliography_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'item_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function activityEventPrefix(): string
    {
        return 'RESERVATION';
    }
}