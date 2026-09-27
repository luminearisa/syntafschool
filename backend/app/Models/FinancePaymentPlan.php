<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rencana pembayaran / cicilan atas satu invoice (§7).
 *
 * Plan membagi sisa tagihan menjadi beberapa jatuh tempo. Ia tidak menerbitkan
 * tagihan baru dan tidak pernah menjadi tempat uang masuk: pelunasan tetap
 * tercatat sebagai payment + allocation ke invoice asli, dan progress angsuran
 * dibaca dari sana. Karena itu satu-satunya angka "milik" plan adalah
 * `total_amount` (berapa besar bagian invoice yang dicicil) dan
 * `baseline_paid_amount` (saldo invoice ketika pembagian itu disepakati).
 *
 * Status plan dan status angsuran memakai enum yang sama, dan keduanya hasil
 * hitung (`FinancePaymentPlanService::sync()`), bukan sesuatu yang dipilih
 * pengguna — sehingga tidak ada jalan untuk "menandai lunas" dari halaman plan.
 */
class FinancePaymentPlan extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_DUE = 'due';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_CANCELLED = 'cancelled';

    /** §7: status plan dan status angsuran memakai tangga yang sama. */
    public const STATUSES = [
        self::STATUS_PLANNED, self::STATUS_DUE, self::STATUS_PARTIAL,
        self::STATUS_PAID, self::STATUS_OVERDUE, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PLANNED => 'Terencana',
        self::STATUS_DUE => 'Jatuh Tempo',
        self::STATUS_PARTIAL => 'Dibayar Sebagian',
        self::STATUS_PAID => 'Lunas',
        self::STATUS_OVERDUE => 'Terlambat',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    /** Toleransi pembulatan rupiah-per-sen; di bawah ini dianggap nol/lunas. */
    public const EPSILON = 0.0001;

    protected $casts = [
        'invoice_id' => 'integer',
        'student_id' => 'integer',
        'cost_center_id' => 'integer',
        'installment_count' => 'integer',
        'start_date' => 'date',
        'total_amount' => 'decimal:2',
        'baseline_paid_amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(FinancePaymentPlanInstallment::class, 'payment_plan_id')->orderBy('sequence');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Plan yang masih berjalan: belum lunas dan belum dibatalkan. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_PAID, self::STATUS_CANCELLED]);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function paidAmount(): float
    {
        return round((float) $this->installments->sum('paid_amount'), 2);
    }

    public function outstandingAmount(): float
    {
        return round((float) $this->installments->sum('outstanding_amount'), 2);
    }

    /** Angsuran berikutnya yang masih terbuka — yang ditagih di loket hari ini. */
    public function nextInstallment(): ?FinancePaymentPlanInstallment
    {
        return $this->installments
            ->filter(fn (FinancePaymentPlanInstallment $i): bool => (float) $i->outstanding_amount > self::EPSILON)
            ->sortBy('sequence')
            ->first();
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    /** Nomor yang disebut sejarah penagihan (§6) saat menautkan dokumen ini. */
    public function documentNumber(): string
    {
        return (string) $this->plan_number;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_PAYMENT_PLAN';
    }
}
