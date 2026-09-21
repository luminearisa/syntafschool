<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Denda anggota (keterlambatan, kerusakan, kehilangan, lain-lain).
 *
 * Tidak ada sistem akuntansi baru: nominal, pembayaran (memakai master
 * `payment_methods`), dan pembebasan (waiver) dicatat di sini, sedangkan
 * riwayat perubahannya ada di jejak audit.
 */
class LibraryFine extends BaseModel
{
    use LogsLibraryActivity, SoftDeletes;

    public const TYPE_OVERDUE = 'overdue';
    public const TYPE_DAMAGE = 'damage';
    public const TYPE_LOST = 'lost';
    public const TYPE_OTHER = 'other';

    public const TYPES = [self::TYPE_OVERDUE, self::TYPE_DAMAGE, self::TYPE_LOST, self::TYPE_OTHER];

    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_WAIVED = 'waived';

    public const STATUSES = [self::STATUS_UNPAID, self::STATUS_PARTIAL, self::STATUS_PAID, self::STATUS_WAIVED];

    /** Status yang masih menyisakan saldo. */
    public const OUTSTANDING_STATUSES = [self::STATUS_UNPAID, self::STATUS_PARTIAL];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'waived_amount' => 'decimal:2',
        'assessed_at' => 'date:Y-m-d',
        'overdue_days' => 'integer',
        'waived_at' => 'datetime',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'member_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LibraryLoan::class, 'loan_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'item_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LibraryFinePayment::class, 'fine_id')->orderBy('id');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', self::OUTSTANDING_STATUSES);
    }

    /** Sisa yang masih harus dibayar. */
    public function outstandingAmount(): float
    {
        return max(0, round((float) $this->amount - (float) $this->paid_amount - (float) $this->waived_amount, 2));
    }

    /** Tentukan status dari nominal (dibayar/dibebaskan sebagian). */
    public function resolveStatus(): string
    {
        if ($this->outstandingAmount() <= 0.0) {
            return (float) $this->waived_amount > 0 && (float) $this->paid_amount <= 0
                ? self::STATUS_WAIVED
                : self::STATUS_PAID;
        }

        return (float) $this->paid_amount > 0 || (float) $this->waived_amount > 0
            ? self::STATUS_PARTIAL
            : self::STATUS_UNPAID;
    }

    public function activityEventPrefix(): string
    {
        return 'FINE';
    }
}