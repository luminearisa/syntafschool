<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Financial transaction (§46, §47, §72) — header jurnal double-entry.
 *
 * SATU-SATUNYA buku besar modul Finance. Setiap transaksi confirmed (payment,
 * expense, refund, transfer, adjustment, opening balance) bermuara ke sini via
 * ledger engine, yang memvalidasi SUM(debit) == SUM(credit) dan menolak periode
 * closed. `reference_type`/`reference_id` menautkan jurnal ke dokumen asalnya.
 *
 * Phase 3: jurnal TIDAK punya tabel sendiri — entitas ini adalah jurnal (§4,
 * §71). `transaction_number` (JRN-…) adalah nomor jurnal, `posted_at`/`posted_by`
 * mencatat siapa dan kapan jurnal menjadi tak-lagi-bisa-diedit (§41), dan
 * `metadata` menyimpan jejak dokumen sumber untuk trace (§38).
 */
class FinancialTransaction extends BaseModel
{
    use LogsFinanceActivity;

    // Nama kelas "FinancialTransaction" akan dipluralisasi Laravel menjadi
    // "financial_transactions"; tabel hasil migrasi bernama "finance_transactions".
    protected $table = 'finance_transactions';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_REFUND = 'refund';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_OPENING = 'opening_balance';

    /** Pengakuan piutang+pendapatan saat invoice terbit (§3, §8). */
    public const TYPE_INVOICE = 'invoice';

    /** Pengakuan kewajiban saat invoice vendor masuk (§3, §27). */
    public const TYPE_PAYABLE = 'payable';

    /** Penghapusan piutang atas beban (§3, §33). */
    public const TYPE_WRITE_OFF = 'write_off';

    public const TYPES = [
        self::TYPE_INVOICE, self::TYPE_REVENUE, self::TYPE_EXPENSE, self::TYPE_PAYABLE,
        self::TYPE_PAYMENT, self::TYPE_REFUND, self::TYPE_TRANSFER,
        self::TYPE_ADJUSTMENT, self::TYPE_WRITE_OFF, self::TYPE_OPENING,
    ];

    public const TYPE_LABELS = [
        self::TYPE_INVOICE => 'Invoice (Akrual)',
        self::TYPE_REVENUE => 'Pendapatan',
        self::TYPE_PAYABLE => 'Hutang (Payable)',
        self::TYPE_EXPENSE => 'Beban',
        self::TYPE_PAYMENT => 'Pembayaran',
        self::TYPE_REFUND => 'Refund',
        self::TYPE_TRANSFER => 'Transfer',
        self::TYPE_ADJUSTMENT => 'Adjustment',
        self::TYPE_WRITE_OFF => 'Write-Off',
        self::TYPE_OPENING => 'Saldo Awal',
    ];

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    /**
     * Status yang dihitung buku besar. Jurnal yang dibalik TETAP dibaca ledger
     * (§51: reverse, jangan hapus) — ia dinolkan oleh jurnal pembaliknya, bukan
     * oleh penghilangan aslinya. Menyaring hanya `posted` membuat pembalikan
     * berbalik menjadi kesalahan dua kali lipat.
     */
    public const LEDGER_STATUSES = [self::STATUS_POSTED, self::STATUS_REVERSED];

    protected $casts = [
        'transaction_date' => 'date',
        'reference_id' => 'integer',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'metadata' => 'array',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(FinancialTransactionLine::class, 'finance_transaction_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FinancePeriod::class, 'period_id');
    }

    /**
     * Dokumen sumber jurnal (invoice / payment / refund / expense request / …).
     * Titik awal penelusuran §38; nilai `reference_type` selalu FQCN milik
     * modul sendiri karena hanya ledger engine yang menulisnya.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'reversed_by_transaction_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class, 'reversed_by_transaction_id');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function isBalanced(): bool
    {
        return abs((float) $this->total_debit - (float) $this->total_credit) < 0.0001;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? (string) $this->type;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_TRANSACTION';
    }
}
