<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Billing (§13–§15) — kewajiban bayar kepada satu pihak (santri/karyawan/…).
 *
 * `reference` unik adalah kunci anti-duplikat generation (§14): kombinasi
 * student|fee|period. Sisa tagihan dihitung dari total dikurangi pembayaran
 * DAN write-off (§31) — angkanya dimiliki FinanceOutstandingRecalculator,
 * bukan oleh service yang mengubahnya. Lewat jatuh tempo & belum lunas =
 * overdue.
 */
class FinanceBilling extends BaseModel
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

    public const SOURCES = ['manual', 'package', 'recurring', 'ppdb', 'boarding', 'library', 'other'];

    public const CUSTOMER_TYPES = ['student', 'employee', 'guardian', 'vendor', 'other'];

    protected $casts = [
        'customer_id' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'scholarship_amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'written_off_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(FinanceBillingItem::class, 'billing_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(FinanceInvoice::class, 'billing_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancePayment::class, 'billing_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ISSUED, self::STATUS_PARTIAL, self::STATUS_OVERDUE]);
    }

    public function isSettled(): bool
    {
        return (float) $this->remaining_amount <= 0.0001;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_BILLING';
    }
}
