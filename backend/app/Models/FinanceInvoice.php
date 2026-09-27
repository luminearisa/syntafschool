<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoice (§16) — dokumen tagihan yang ditagihkan ke customer, dibuat dari
 * billing atau transaksi lain (PPDB, boarding, library fine).
 *
 * `paid_amount` bergerak HANYA lewat payment yang sudah confirmed (§21): sebelum
 * approval, saldo invoice tidak berubah. `remaining_amount` dan `status` tidak
 * pernah ditulis langsung oleh pemanggil — keduanya hasil hitung
 * FinanceOutstandingRecalculator dari `total − paid − written_off` (§31).
 */
class FinanceInvoice extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_PARTIAL,
        self::STATUS_PAID, self::STATUS_OVERDUE, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_ISSUED => 'Diterbitkan',
        self::STATUS_PARTIAL => 'Dibayar Sebagian',
        self::STATUS_PAID => 'Lunas',
        self::STATUS_OVERDUE => 'Jatuh Tempo',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    public const CUSTOMER_TYPES = ['student', 'employee', 'guardian', 'vendor', 'other'];

    protected $casts = [
        'customer_id' => 'integer',
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'written_off_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    public function billing(): BelongsTo
    {
        return $this->belongsTo(FinanceBilling::class, 'billing_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinanceInvoiceItem::class, 'invoice_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancePayment::class, 'invoice_id');
    }

    /**
     * Alokasi pembayaran (§15–§18 Phase 2). `paid_amount` invoice ini adalah
     * cache = SUM(amount) dari alokasi yang payment-nya confirmed.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(FinancePaymentAllocation::class, 'invoice_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Jurnal yang lahir dari invoice ini (akrual, pelunasan, pembatalan) — §38. */
    public function financialTransactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class, 'reference_id')
            ->where('reference_type', self::class);
    }

    /** Write-off piutang (§33) yang menyentuh invoice ini. */
    public function writeOffs(): HasMany
    {
        return $this->hasMany(FinanceAdjustment::class, 'invoice_id')
            ->where('kind', FinanceAdjustment::KIND_WRITE_OFF);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ISSUED, self::STATUS_PARTIAL, self::STATUS_OVERDUE]);
    }

    public function isSettled(): bool
    {
        return (float) $this->remaining_amount <= 0.0001;
    }

    /** Sisa tagihan yang dihapus sebagai beban, bukan dibayar (§33). */
    public function writtenOffAmount(): float
    {
        return round((float) $this->written_off_amount, 2);
    }

    public function isFullyWrittenOff(): bool
    {
        return $this->writtenOffAmount() > 0.0001 && $this->isSettled();
    }

    /**
     * Status AR untuk laporan (§21): OPEN/PARTIAL/PAID/OVERDUE/WRITTEN_OFF.
     * Beda dengan `status` dokumen — invoice yang dilunasi sebagian lalu dihapus
     * sisa piutangnya tetap WRITTEN_OFF di mata penagihan.
     */
    public function receivableStatus(): string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return self::STATUS_CANCELLED;
        }

        if ($this->isFullyWrittenOff()) {
            return 'written_off';
        }

        if ($this->isSettled()) {
            return self::STATUS_PAID;
        }

        if ($this->isOverdue()) {
            return self::STATUS_OVERDUE;
        }

        return (float) $this->paid_amount > 0.0001 ? self::STATUS_PARTIAL : self::STATUS_ISSUED;
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PARTIAL], true)
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_INVOICE';
    }
}
