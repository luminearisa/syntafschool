<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu kali penitipan barang oleh seorang santri.
 *
 * Siklus: `active` (barang di gudang titipan, bisa dipinjam-kembali) →
 * `returned` (titipan ditutup, seluruh barang diserahkan permanen ke santri).
 */
class BoardingItemDeposit extends BaseModel
{
    use LogsBoardingActivity;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETURNED = 'returned';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_RETURNED];

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Aktif',
        self::STATUS_RETURNED => 'Selesai (Diambil)',
    ];

    protected $casts = [
        'deposited_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BoardingItemDepositItem::class, 'deposit_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }
}
