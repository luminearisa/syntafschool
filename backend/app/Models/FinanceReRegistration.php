<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pendaftaran ulang / Daftar Ulang (§2–§7 Phase 2).
 *
 * Menautkan satu santri + tahun ajaran tujuan ke Billing/Invoice Phase 1 dan
 * melacak status workflow. Bukan mesin tagihan baru: uang tetap mengalir lewat
 * `FinanceBilling`/`FinanceInvoice`/`FinancePayment` yang sudah ada.
 *
 * Status (§6): ELIGIBLE → BILLED → UNPAID → PARTIAL → PAID → COMPLETED,
 * dengan cabang EXPIRED (lewat batas tanpa lunas) dan CANCELLED (dibatalkan).
 * `activated` menandai integrasi Academic (§7): tahun ajaran berikutnya aktif.
 */
class FinanceReRegistration extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_ELIGIBLE = 'eligible';

    public const STATUS_BILLED = 'billed';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ELIGIBLE, self::STATUS_BILLED, self::STATUS_UNPAID, self::STATUS_PARTIAL,
        self::STATUS_PAID, self::STATUS_COMPLETED, self::STATUS_EXPIRED, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_ELIGIBLE => 'Berhak Daftar Ulang',
        self::STATUS_BILLED => 'Ditagihkan',
        self::STATUS_UNPAID => 'Belum Bayar',
        self::STATUS_PARTIAL => 'Dibayar Sebagian',
        self::STATUS_PAID => 'Lunas',
        self::STATUS_COMPLETED => 'Selesai',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    /** Status yang masih dianggap "belum selesai" untuk pelacakan tunggakan. */
    public const OPEN_STATUSES = [
        self::STATUS_ELIGIBLE, self::STATUS_BILLED, self::STATUS_UNPAID, self::STATUS_PARTIAL,
    ];

    protected $casts = [
        'student_id' => 'integer',
        'amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'generation_attempt' => 'integer',
        'due_date' => 'date',
        'billed_at' => 'datetime',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'activated' => 'boolean',
        'activated_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function currentAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'current_academic_year_id');
    }

    public function nextAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'next_academic_year_id');
    }

    public function feePackage(): BelongsTo
    {
        return $this->belongsTo(FinanceFeePackage::class, 'fee_package_id');
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(FinanceBilling::class, 'billing_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'revenue_account_id');
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'receivable_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_COMPLETED], true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_RE_REGISTRATION';
    }
}
