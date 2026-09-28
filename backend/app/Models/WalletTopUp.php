<?php

namespace App\Models;

use App\Models\Concerns\LogsWalletActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Permintaan top up wallet.
 *
 * Tiga hal yang sengaja **tidak** digabung jadi satu status (§14):
 * `status` (daur hidup permintaan), `payment_status` (daur hidup pembayaran),
 * dan baris ledger di `transaction_id` (saat saldo benar-benar bertambah).
 *
 * Saldo hanya bertambah setelah pembayaran terverifikasi — oleh webhook
 * gateway atau oleh petugas berpermission `wallet.topup.verify`.
 */
class WalletTopUp extends BaseModel
{
    use LogsWalletActivity;

    // The schema intentionally uses the compact name `wallet_topups`.
    protected $table = 'wallet_topups';

    public const METHOD_GATEWAY = 'gateway';

    public const METHOD_MANUAL = 'manual';

    public const METHODS = [self::METHOD_GATEWAY, self::METHOD_MANUAL];

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_AWAITING_VERIFICATION = 'awaiting_verification';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PENDING_PAYMENT, self::STATUS_AWAITING_VERIFICATION,
        self::STATUS_VERIFIED, self::STATUS_REJECTED, self::STATUS_EXPIRED,
        self::STATUS_CANCELLED, self::STATUS_COMPLETED,
    ];

    /** Status yang sudah final dan tidak bisa diubah lagi. */
    public const FINAL_STATUSES = [
        self::STATUS_COMPLETED, self::STATUS_REJECTED, self::STATUS_CANCELLED,
    ];

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_FAILED = 'failed';

    public const PAYMENT_EXPIRED = 'expired';

    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID, self::PAYMENT_PENDING, self::PAYMENT_PAID,
        self::PAYMENT_FAILED, self::PAYMENT_EXPIRED, self::PAYMENT_REFUNDED,
    ];

    /**
     * Urutan kemajuan pembayaran. Webhook dengan peringkat lebih rendah dari
     * status tersimpan ditolak, sehingga notifikasi ganda (atau urutan baliknya
     * `expire` setelah `settlement`) tidak pernah menghapus pembayaran yang sah.
     */
    public const PAYMENT_RANKS = [
        self::PAYMENT_UNPAID => 0,
        self::PAYMENT_PENDING => 1,
        self::PAYMENT_FAILED => 2,
        self::PAYMENT_EXPIRED => 2,
        self::PAYMENT_REFUNDED => 3,
        self::PAYMENT_PAID => 4,
    ];

    /** Antrean yang harus muncul di layar verifikasi petugas. */
    public const VERIFYABLE_STATUSES = [self::STATUS_AWAITING_VERIFICATION];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_payload' => 'array',
        'payment_expired_at' => 'datetime',
        'paid_at' => 'datetime',
        'verified_at' => 'datetime',
        'transferred_on' => 'date:Y-m-d',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'transaction_id');
    }

    /** Rujukan ledger balik dari sisi top up. */
    public function ledgerEntry(): MorphOne
    {
        return $this->morphOne(WalletTransaction::class, 'reference');
    }

    public function isAwaitingVerification(): bool
    {
        return $this->status === self::STATUS_AWAITING_VERIFICATION;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    /** Peringkat status pembayaran; status tak dikenal dianggap paling rendah. */
    public static function paymentRankOf(?string $status): int
    {
        return self::PAYMENT_RANKS[(string) $status] ?? -1;
    }

    public function activityEventPrefix(): string
    {
        return 'WALLET_TOPUP';
    }
}
