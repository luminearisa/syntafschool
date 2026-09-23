<?php

namespace App\Models;

use App\Models\Concerns\LogsWalletActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Koreksi saldo oleh finance: credit (menambah) atau debit (mengurangi).
 *
 * Alasan wajib dan ideally merujuk nomor dokumen, karena adjustment adalah satu
 * satunya jalur di luar pembayaran nyata yang mengubah uang santri/karyawan.
 * Pemisahan kekuasaan dijaga permission: pembuat (`wallet.adjustment.create`)
 * bukan penyetuju (`wallet.adjustment.approve`) — lihat §20 dan
 * `WalletAdjustmentService::post()`.
 */
class WalletAdjustment extends BaseModel
{
    use LogsWalletActivity;

    public const DIRECTION_CREDIT = 'credit';

    public const DIRECTION_DEBIT = 'debit';

    public const DIRECTIONS = [self::DIRECTION_CREDIT, self::DIRECTION_DEBIT];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_POSTED,
        self::STATUS_REJECTED, self::STATUS_CANCELLED,
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'transaction_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function ledgerType(): string
    {
        return $this->direction === self::DIRECTION_CREDIT
            ? WalletTransaction::TYPE_ADJUSTMENT_CREDIT
            : WalletTransaction::TYPE_ADJUSTMENT_DEBIT;
    }

    public function activityEventPrefix(): string
    {
        return 'WALLET_ADJUSTMENT';
    }
}
