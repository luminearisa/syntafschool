<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rekonsiliasi bank (§44, §45) — header satu periode rekonsiliasi per rekening.
 * `difference = bank_balance - system_balance`; status RECONCILED bila nol.
 */
class FinanceReconciliation extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_DISCREPANCY = 'discrepancy';

    public const STATUSES = [self::STATUS_IN_PROGRESS, self::STATUS_RECONCILED, self::STATUS_DISCREPANCY];

    public const STATUS_LABELS = [
        self::STATUS_IN_PROGRESS => 'Berjalan',
        self::STATUS_RECONCILED => 'Rekonsiliasi',
        self::STATUS_DISCREPANCY => 'Selisih',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'bank_balance' => 'decimal:2',
        'system_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'reconciled_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccount::class, 'bank_account_id');
    }

    /** Rekening koran yang menjadi dasar rekonsiliasi (Phase 4 §4); null bila barisnya diketik tangan. */
    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(FinanceBankStatement::class, 'bank_statement_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinanceReconciliationItem::class, 'reconciliation_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_RECONCILIATION';
    }
}
