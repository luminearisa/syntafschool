<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tagihan pendaftaran (atau daftar ulang) untuk satu pendaftaran.
 *
 * Pembayaran manual memakai master `payment_methods`; kolom provider disiapkan
 * untuk Midtrans. Tidak ada sistem akuntansi baru.
 */
class PpdbInvoice extends BaseModel
{
    use LogsPpdbActivity, SoftDeletes;

    public const TYPE_REGISTRATION = 'registration';
    public const TYPE_RE_REGISTRATION = 're_registration';
    public const TYPE_OTHER = 'other';

    public const TYPES = [self::TYPE_REGISTRATION, self::TYPE_RE_REGISTRATION, self::TYPE_OTHER];

    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_UNPAID, self::STATUS_PENDING, self::STATUS_PAID,
        self::STATUS_FAILED, self::STATUS_EXPIRED, self::STATUS_CANCELLED, self::STATUS_REFUNDED,
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_date' => 'date:Y-m-d',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(PpdbRegistration::class, 'registration_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PpdbInvoiceItem::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PpdbPayment::class, 'invoice_id')->orderByDesc('id');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_EXPIRED]);
    }

    /** Sisa yang belum dibayar. */
    public function outstandingAmount(): float
    {
        return max(0, round((float) $this->total_amount - (float) $this->paid_amount, 2));
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_INVOICE';
    }
}