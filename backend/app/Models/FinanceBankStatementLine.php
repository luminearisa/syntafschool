<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris rekening koran (Phase 4 §4) — satu mutasi menurut BANK, lengkap dengan
 * status pencocokannya terhadap buku kita.
 *
 * Status mengikuti §4: UNMATCHED → MATCHED → RECONCILED, atau EXCLUDED (baris
 * yang memang bukan milik kita, mis. rekening koran gabungan yang sebagian
 * dibayarkan unit lain). `journal_line_id` menunjuk baris JURNAL buku kita —
 * bukan dokumen — karena semua pergerakan uang pasti lewat jurnal (§2) sedangkan
 * dokumen bank-nya (`finance_bank_transactions`) hanya ada untuk yang diketik
 * manual; payment via transfer bank tidak punya baris itu.
 *
 * Baris ini tidak pernah membuat jurnalnya sendiri (§4): ia bukti dari sisi bank,
 * penyeimbang klaim "uangnya sudah masuk buku kita atau belum".
 */
class FinanceBankStatementLine extends BaseModel
{
    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_EXCLUDED = 'excluded';

    public const STATUSES = [
        self::STATUS_UNMATCHED, self::STATUS_MATCHED, self::STATUS_RECONCILED, self::STATUS_EXCLUDED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_UNMATCHED => 'Belum Cocok',
        self::STATUS_MATCHED => 'Cocok',
        self::STATUS_RECONCILED => 'Direkonsiliasi',
        self::STATUS_EXCLUDED => 'Dikecualikan',
    ];

    public const METHODS = [
        'external_id', 'reference', 'document_number', 'amount_date', 'amount', 'manual',
    ];

    protected $casts = [
        'line_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
        'matched_at' => 'datetime',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(FinanceBankStatement::class, 'bank_statement_id');
    }

    /** Baris jurnal buku kita yang menjadi lawan pencocokan. */
    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(FinancialTransactionLine::class, 'journal_line_id');
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    /** Perpindahan uang ke rekening: sisi kredit rekening koran. */
    public function isInflow(): bool
    {
        return (float) $this->credit > 0;
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_MATCHED, self::STATUS_RECONCILED, self::STATUS_EXCLUDED], true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }
}
