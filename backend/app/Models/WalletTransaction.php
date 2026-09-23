<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Satu baris buku besar wallet — satu-satunya jalan mengubah saldo.
 *
 * Baris ledger bersifat append-only: tidak diedit, tidak dihapus. Bila ada
 * kesalahan dibuat baris lawan bertipe `reversal` dan baris asli ditandai
 * `reversed`, sehingga jejak aslinya tetap terbaca (§9).
 *
 * `balance_before` + credit − debit = `balance_after` selalu benar karena
 * keduanya dihitung di satu transaksi database dengan baris wallet terkunci.
 */
class WalletTransaction extends BaseModel
{
    use Concerns\LogsWalletActivity;

    public const TYPE_TOPUP = 'topup';

    public const TYPE_WITHDRAW = 'withdraw';

    public const TYPE_ADJUSTMENT_CREDIT = 'adjustment_credit';

    public const TYPE_ADJUSTMENT_DEBIT = 'adjustment_debit';

    public const TYPE_REFUND = 'refund';

    public const TYPE_REVERSAL = 'reversal';

    public const TYPES = [
        self::TYPE_TOPUP, self::TYPE_WITHDRAW, self::TYPE_ADJUSTMENT_CREDIT,
        self::TYPE_ADJUSTMENT_DEBIT, self::TYPE_REFUND, self::TYPE_REVERSAL,
    ];

    public const DIRECTION_CREDIT = 'credit';

    public const DIRECTION_DEBIT = 'debit';

    public const DIRECTIONS = [self::DIRECTION_CREDIT, self::DIRECTION_DEBIT];

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    public const STATUSES = [self::STATUS_POSTED, self::STATUS_REVERSED];

    /** Tipe yang menambah saldo. */
    public const CREDIT_TYPES = [
        self::TYPE_TOPUP, self::TYPE_ADJUSTMENT_CREDIT, self::TYPE_REFUND,
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** Sumber pembukuan: WalletTopUp | WalletWithdrawal | WalletAdjustment | WalletReversal. */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    /** Baris asli yang dikoreksi baris reversal ini. */
    public function sourceTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_transaction_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'source_transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    /** Tanda (+) atau (−) untuk tampilan riwayat. */
    public function signedAmount(): float
    {
        return $this->isCredit()
            ? round((float) $this->amount, 2)
            : round((float) $this->amount * -1, 2);
    }

    public function activityEventPrefix(): string
    {
        return 'WALLET_TRANSACTION';
    }
}
