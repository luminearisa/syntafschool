<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transaksi bank (§41) — deposit/withdrawal/transfer/fee/adjustment, tiap baris
 * wajib punya reference. Saldo rekening digerakkan ledger engine.
 */
class FinanceBankTransaction extends BaseModel
{
    use LogsFinanceActivity;

    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_FEE = 'fee';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPES = [
        self::TYPE_DEPOSIT, self::TYPE_WITHDRAWAL, self::TYPE_TRANSFER, self::TYPE_FEE, self::TYPE_ADJUSTMENT,
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccount::class, 'bank_account_id');
    }

    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'finance_transaction_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(FinanceTransfer::class, 'transfer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_BANK_TRANSACTION';
    }
}
