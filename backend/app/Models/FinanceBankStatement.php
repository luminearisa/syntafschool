<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rekening koran bank (Phase 4 §4) — header satu mutasi bank dari bank.
 *
 * Hanya DATA BANK. Mengimpor statement tidak pernah membuat jurnal (§4), dan
 * tidak ada kolom saldo buku di sini: pembandingnya (`Book Balance`) selalu
 * dibaca langsung dari rekening `finance_bank_accounts` supaya tidak ada dua
 * sumber kebenaran yang bisa berbeda.
 */
class FinanceBankStatement extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_IMPORTED, self::STATUS_IN_REVIEW, self::STATUS_RECONCILED, self::STATUS_CANCELLED];

    public const STATUS_LABELS = [
        self::STATUS_IMPORTED => 'Diimpor',
        self::STATUS_IN_REVIEW => 'Direview',
        self::STATUS_RECONCILED => 'Direkonsiliasi',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_PASTE = 'paste';

    public const SOURCE_CSV = 'csv';

    public const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_PASTE, self::SOURCE_CSV];

    protected $casts = [
        'statement_date' => 'date',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccount::class, 'bank_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinanceBankStatementLine::class, 'bank_statement_id')->orderBy('sequence')->orderBy('id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(FinanceReconciliation::class, 'bank_statement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_IMPORTED, self::STATUS_IN_REVIEW]);
    }

    /** Total mutasi baris; harus sama dengan closing − opening (§4). */
    public function movementTotal(): float
    {
        return round((float) $this->lines->sum('amount'), 2);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_IMPORTED, self::STATUS_IN_REVIEW], true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_BANK_STATEMENT';
    }
}
