<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adjustment (§51) — koreksi oleh Finance Manager (selisih rekonsiliasi,
 * koreksi kas/piutang). Tidak mengedit transaksi lama: adjustment diposting
 * sebagai jurnal baru setelah approval.
 *
 * Phase 3 memakai tabel yang sama untuk write-off piutang (§33) lewat `kind`
 * + `invoice_id`, alih-alih membuat entitas koreksi kedua: keduanya butuh
 * reason, approval, dan audit trail yang identik.
 */
class FinanceAdjustment extends BaseModel
{
    use LogsFinanceActivity;

    public const KIND_GENERAL = 'general';

    public const KIND_WRITE_OFF = 'write_off';

    public const KINDS = [self::KIND_GENERAL, self::KIND_WRITE_OFF];

    public const KIND_LABELS = [
        self::KIND_GENERAL => 'Koreksi akun',
        self::KIND_WRITE_OFF => 'Write-off piutang',
    ];

    public const DIRECTION_INCREASE = 'increase';

    public const DIRECTION_DECREASE = 'decrease';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_POSTED = 'posted';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_POSTED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SUBMITTED => 'Diajukan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_POSTED => 'Diposting',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'adjustment_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    /** Invoice yang dihapus piutangnya (khusus `kind` = write_off, §33). */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }

    public function contraAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'contra_account_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceCashAccount::class, 'cash_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccount::class, 'bank_account_id');
    }

    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'finance_transaction_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? (string) $this->kind;
    }

    /** Nomor yang disebut sejarah penagihan (§6) saat menautkan dokumen ini. */
    public function documentNumber(): string
    {
        return (string) $this->adjustment_number;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_ADJUSTMENT';
    }
}
