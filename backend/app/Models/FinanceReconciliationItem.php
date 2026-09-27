<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris rekonsiliasi (§44) — satu baris rekening koran vs transaksi sistem,
 * dengan status matching.
 */
class FinanceReconciliationItem extends BaseModel
{
    public const MATCH_MATCHED = 'matched';

    public const MATCH_UNMATCHED = 'unmatched';

    public const MATCH_IGNORED = 'ignored';

    public const MATCH_ADJUSTED = 'adjusted';

    public const MATCH_RECONCILED = 'reconciled';

    public const MATCH_STATUSES = [
        self::MATCH_MATCHED, self::MATCH_UNMATCHED, self::MATCH_IGNORED, self::MATCH_ADJUSTED, self::MATCH_RECONCILED,
    ];

    public const MATCH_LABELS = [
        self::MATCH_MATCHED => 'Cocok',
        self::MATCH_UNMATCHED => 'Belum Cocok',
        self::MATCH_IGNORED => 'Dikecualikan',
        self::MATCH_ADJUSTED => 'Disesuaikan',
        self::MATCH_RECONCILED => 'Direkonsiliasi',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'bank_amount' => 'decimal:2',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(FinanceReconciliation::class, 'reconciliation_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceBankTransaction::class, 'bank_transaction_id');
    }

    /** Baris rekening koran sumber item ini (Phase 4 §4). */
    public function bankStatementLine(): BelongsTo
    {
        return $this->belongsTo(FinanceBankStatementLine::class, 'bank_statement_line_id');
    }

    /** Baris jurnal buku kita yang dicocokkan ke baris bank ini. */
    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(FinancialTransactionLine::class, 'journal_line_id');
    }
}
