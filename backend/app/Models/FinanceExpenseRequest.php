<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Expense request (§30–§33) — pengajuan pengeluaran dengan alur approval
 * bertingkat (threshold configurable). Status `paid` berarti uang sudah keluar
 * dan jurnal sudah diposting (Dr Expense, Cr Cash/Bank/Payable).
 *
 * Kolom `budget_*` membekukan hasil pemeriksaan anggaran (§10) saat pengajuan
 * masuk alur: kebijakan yang berlaku, seberapa besar nominalnya melebihi sisa
 * anggaran, dan siapa yang menerima kelebihan itu. Nilai-nilai ini adalah jejak
 * keputusan, bukan sumber realisasi — actual tetap hanya dari buku besar.
 */
class FinanceExpenseRequest extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED,
        self::STATUS_REJECTED, self::STATUS_PROCESSING, self::STATUS_PAID, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SUBMITTED => 'Diajukan',
        self::STATUS_UNDER_REVIEW => 'Direview',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_PROCESSING => 'Diproses',
        self::STATUS_PAID => 'Dibayar',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'due_date' => 'date',
        'current_approval_level' => 'integer',
        'required_approval_levels' => 'integer',
        'budget_shortfall' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'budget_override_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FinanceVendor::class, 'vendor_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceCashAccount::class, 'cash_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccount::class, 'bank_account_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function budgetOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'budget_override_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'expense_request_id');
    }

    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW]);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    /** Nominalnya melewati sisa anggaran saat diperiksa (§10 "Budget Exceeded"). */
    public function overranBudget(): bool
    {
        return (float) $this->budget_shortfall > 0.005;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_EXPENSE_REQUEST';
    }
}
