<?php

namespace App\Models;

use App\Models\Concerns\LogsWalletActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan penarikan dana wallet.
 *
 * Sejak permintaan dibuat, nominalnya **ditahan** (`held_amount`) supaya saldo
 * yang sama tidak dipakai dua kali (§17). Tahanan baru dilepas saat penarikan
 * selesai, ditolak, atau gagal — dan `debited` menandai apakah ledger debit
 * sudah terbit, sehingga jalur gagal (§18) tahu kapan harus mengembalikan saldo.
 */
class WalletWithdrawal extends BaseModel
{
    use LogsWalletActivity;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PROCESSING,
        self::STATUS_COMPLETED, self::STATUS_REJECTED, self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** Status akhir: tahanan pasti sudah lepas dan tidak ada aksi lanjutan. */
    public const FINAL_STATUSES = [
        self::STATUS_COMPLETED, self::STATUS_REJECTED, self::STATUS_CANCELLED,
    ];

    /** Status yang masih menahan saldo pemilik wallet. */
    public const HOLDING_STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PROCESSING,
    ];

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_CASH = 'cash';

    public const METHODS = [self::METHOD_BANK_TRANSFER, self::METHOD_CASH];

    protected $casts = [
        'amount' => 'decimal:2',
        'held_amount' => 'decimal:2',
        'hold_released' => 'boolean',
        'debited' => 'boolean',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function schoolAccount(): BelongsTo
    {
        return $this->belongsTo(WalletBankAccount::class, 'wallet_bank_account_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'transaction_id');
    }

    public function isHolding(): bool
    {
        return in_array($this->status, self::HOLDING_STATUSES, true) && ! $this->hold_released;
    }

    /** Sudah bisa dikirim ke antrean pencairan? */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    public function activityEventPrefix(): string
    {
        return 'WALLET_WITHDRAWAL';
    }
}
