<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anggaran (§8) dengan alur approval (§9).
 *
 * Satu baris adalah satu *budget line*: §8 mencontohkan "IT — Internet 120jt,
 * Software 80jt", yang di sini menjadi tiga baris dengan (fiscal_year,
 * cost_center) sama dan akun berbeda. Keunian scope-nya ditegakkan di database,
 * sehingga tidak ada entitas Budget Line / Budget Allocation terpisah yang
 * menyimpan angka yang sama dua kali.
 *
 * `used_amount` adalah cache realisasi yang dinaikkan saat expense/PO diposting,
 * sehingga Budget vs Actual (§55) murah dihitung. Sumber kebenarannya tetap
 * buku besar: `FinanceBudgetService::position()` menghitung ulang actual dari
 * jurnal posted dan melaporkan selisih terhadap cache ini.
 */
class FinanceBudget extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED,
        self::STATUS_ACTIVE, self::STATUS_REJECTED, self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SUBMITTED => 'Diajukan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_ACTIVE => 'Aktif',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_CLOSED => 'Ditutup',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    protected $casts = [
        'fiscal_year' => 'integer',
        'budget_amount' => 'decimal:2',
        'used_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_ACTIVE]);
    }

    public function remaining(): float
    {
        return (float) $this->budget_amount - (float) $this->used_amount;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_BUDGET';
    }
}
