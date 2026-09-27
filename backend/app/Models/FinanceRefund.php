<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refund (§29) — pengembalian dana atas sebuah payment. Payment asli TIDAK
 * dihapus; refund memposting jurnal balik (Dr Refund/Receivable, Cr Cash/Bank)
 * dan menyesuaikan invoice.
 */
class FinanceRefund extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_APPROVED, self::STATUS_PROCESSING,
        self::STATUS_COMPLETED, self::STATUS_REJECTED, self::STATUS_FAILED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_REQUESTED => 'Diajukan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_PROCESSING => 'Diproses',
        self::STATUS_COMPLETED => 'Selesai',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_FAILED => 'Gagal',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FinancePayment::class, 'payment_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceCashAccount::class, 'cash_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccount::class, 'bank_account_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_REQUESTED, self::STATUS_APPROVED, self::STATUS_PROCESSING]);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_REFUND';
    }
}
