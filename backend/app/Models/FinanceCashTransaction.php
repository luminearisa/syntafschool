<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transaksi kas standalone (Phase 4 §2) — uang masuk/keluar yang tidak lahir
 * dari tagihan, pengeluaran ber-approval, atau transfer: setoran ke kas besar,
 * penarikan untuk kas kecil, pengisian ulang laci kantin.
 *
 * Baris ini adalah DOKUMEN, bukan catatan saldo. Saldo `KAS-*` bergerak hanya
 * lewat jurnal yang dibuat service-nya, jadi menghapus/mengedit baris ini tidak
 * pernah mengubah buku besar (§1: tidak ada transaksi kas tanpa journal).
 */
class FinanceCashTransaction extends BaseModel
{
    use LogsFinanceActivity;

    public const TYPE_CASH_IN = 'cash_in';

    public const TYPE_CASH_OUT = 'cash_out';

    public const TYPES = [
        self::TYPE_CASH_IN, self::TYPE_CASH_OUT,
    ];

    public const TYPE_LABELS = [
        self::TYPE_CASH_IN => 'Kas Masuk',
        self::TYPE_CASH_OUT => 'Kas Keluar',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceCashAccount::class, 'cash_account_id');
    }

    /** Akun COA lawan — pasangan yang membuat jurnal seimbang. */
    public function contraAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'contra_account_id');
    }

    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'finance_transaction_id');
    }

    public function cashierSession(): BelongsTo
    {
        return $this->belongsTo(FinanceCashierSession::class, 'cashier_session_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isInflow(): bool
    {
        return $this->type === self::TYPE_CASH_IN;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_CASH_TRANSACTION';
    }
}
