<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transfer internal (§42, §43) — bank→bank atau kas→kas. Jurnal: Dr akun tujuan,
 * Cr akun sumber; total aset tidak berubah. Butuh approval sebelum dieksekusi.
 *
 * Alur status §3: draft → submitted → approved → completed, bercabang ke rejected
 * atau cancelled. `idempotency_key` (unik per pembuat) membuat permintaan ulang
 * mengembalikan baris yang sama, dan `requires_secondary_approval` mencatat bahwa
 * saat diajukan transfer ini berada di atas ambang matriks approval §16.
 *
 * source/destination polymorphic sederhana (type + id) karena bisa cash atau bank.
 */
class FinanceTransfer extends BaseModel
{
    use LogsFinanceActivity;

    public const TYPE_BANK = 'bank';

    public const TYPE_CASH = 'cash';

    public const SIDE_BANK = 'bank_account';

    public const SIDE_CASH = 'cash_account';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED,
        self::STATUS_REJECTED, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SUBMITTED => 'Diajukan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_COMPLETED => 'Selesai',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'destination_id' => 'integer',
        'amount' => 'decimal:2',
        'transfer_date' => 'date',
        'requires_secondary_approval' => 'boolean',
        'approved_at' => 'datetime',
        'secondary_approved_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Penyetuju kedua untuk transfer di atas ambang nominal (§16). */
    public function secondaryApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'secondary_approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Yang membatalkan transfer sebelum dieksekusi (§3 CANCELLED). */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'finance_transaction_id');
    }

    public function sourceCashAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceCashAccount::class, 'source_id');
    }

    public function sourceBankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccount::class, 'source_id');
    }

    public function destinationCashAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceCashAccount::class, 'destination_id');
    }

    public function destinationBankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccount::class, 'destination_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    /** Transfer besar tanpa penyetuju kedua belum boleh dieksekusi (§16). */
    public function awaitsSecondaryApproval(): bool
    {
        return (bool) $this->requires_secondary_approval && $this->secondary_approved_by === null;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_TRANSFER';
    }
}
