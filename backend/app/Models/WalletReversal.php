<?php

namespace App\Models;

use App\Models\Concerns\LogsWalletActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembatalan sebuah transaksi ledger yang salah catat.
 *
 * Transaksi asli **tidak pernah** diubah nominalnya dan tidak dihapus: dibuat
 * baris ledger lawan (`reversal_transaction_id`) dan baris asli ditandai
 * `reversed`. Rekonsiliasi tetap bisa menelusuri kedua sisi (§9, FLOW 8).
 */
class WalletReversal extends BaseModel
{
    use LogsWalletActivity;

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** Baris yang dikoreksi. */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'transaction_id');
    }

    /** Baris lawan yang dihasilkan koreksi ini. */
    public function reversalTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'reversal_transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityEventPrefix(): string
    {
        return 'WALLET_REVERSAL';
    }
}
