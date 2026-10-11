<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu barang di dalam satu titipan.
 *
 * Status: `in_storage` (di gudang) → `borrowed` (dipinjam santri) → kembali
 * `in_storage` (loan ditutup) → `returned_permanently` (diserahkan permanen
 * saat titipan ditutup atau barang diambil selamanya).
 */
class BoardingItemDepositItem extends BaseModel
{
    use LogsBoardingActivity;
    use SoftDeletes;

    public const TYPE_LAPTOP = 'laptop';
    public const TYPE_IPAD = 'ipad';
    public const TYPE_HP = 'hp';
    public const TYPE_KAMERA = 'kamera';
    public const TYPE_CUSTOM = 'custom';

    public const TYPES = [
        self::TYPE_LAPTOP, self::TYPE_IPAD, self::TYPE_HP, self::TYPE_KAMERA, self::TYPE_CUSTOM,
    ];

    public const TYPE_LABELS = [
        self::TYPE_LAPTOP => 'Laptop',
        self::TYPE_IPAD => 'iPad/Tablet',
        self::TYPE_HP => 'Handphone',
        self::TYPE_KAMERA => 'Kamera',
        self::TYPE_CUSTOM => 'Lainnya',
    ];

    public const STATUS_IN_STORAGE = 'in_storage';
    public const STATUS_BORROWED = 'borrowed';
    public const STATUS_RETURNED_PERMANENTLY = 'returned_permanently';

    public const STATUSES = [
        self::STATUS_IN_STORAGE, self::STATUS_BORROWED, self::STATUS_RETURNED_PERMANENTLY,
    ];

    public const STATUS_LABELS = [
        self::STATUS_IN_STORAGE => 'Di Gudang',
        self::STATUS_BORROWED => 'Sedang Dipinjam',
        self::STATUS_RETURNED_PERMANENTLY => 'Diambil Permanen',
    ];

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(BoardingItemDeposit::class, 'deposit_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(BoardingItemDepositItemLoan::class, 'item_id')
            ->orderByDesc('borrowed_at');
    }

    /** Pinjaman yang sedang terbuka (belum dikembalikan), jika ada. */
    public function currentLoan(): HasOne
    {
        return $this->hasOne(BoardingItemDepositItemLoan::class, 'item_id')
            ->whereNull('returned_at')
            ->latestOfMany('borrowed_at');
    }

    public function scopeInStorage(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_STORAGE);
    }

    public function itemTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->item_type] ?? (string) $this->item_type;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }
}
