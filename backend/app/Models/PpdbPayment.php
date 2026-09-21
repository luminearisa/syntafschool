<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembayaran sebuah tagihan: manual (transfer + verifikasi petugas) atau
 * Midtrans (online, lewat callback penyedia).
 */
class PpdbPayment extends BaseModel
{
    use LogsPpdbActivity;

    public const METHOD_MANUAL = 'manual';
    public const METHOD_MIDTRANS = 'midtrans';

    public const METHODS = [self::METHOD_MANUAL, self::METHOD_MIDTRANS];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_FAILED,
        self::STATUS_EXPIRED, self::STATUS_REFUNDED,
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PpdbInvoice::class, 'invoice_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_PAYMENT';
    }
}