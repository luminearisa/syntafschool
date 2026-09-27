<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Payment (§19–§25) — uang yang dibayarkan untuk invoice/billing.
 *
 * Status `pending` = menunggu verifikasi (manual transfer / gateway). Saldo
 * invoice TIDAK berubah dan ledger TIDAK diposting sampai payment `paid`
 * (§21). Idempotensi webhook gateway dijaga unique (gateway, gateway_transaction_id)
 * di skema (§66), dan pencatatan pembayaran dijaga `idempotency_key` (unik per
 * pembuat, §4): permintaan ulang mengembalikan pembayaran yang sudah ada, bukan
 * jurnal kas kedua atas slip yang sama.
 */
class FinancePayment extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_PARTIAL = 'partial';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_FAILED, self::STATUS_EXPIRED,
        self::STATUS_CANCELLED, self::STATUS_REFUNDED, self::STATUS_PARTIAL,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu Verifikasi',
        self::STATUS_PAID => 'Lunas',
        self::STATUS_FAILED => 'Gagal',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
        self::STATUS_CANCELLED => 'Dibatalkan',
        self::STATUS_REFUNDED => 'Dikembalikan',
        self::STATUS_PARTIAL => 'Sebagian',
    ];

    public const METHOD_CASH = 'cash';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_VA = 'virtual_account';

    public const METHOD_GATEWAY = 'payment_gateway';

    public const METHOD_WALLET = 'wallet';

    public const METHOD_MANUAL = 'manual_transfer';

    public const METHODS = [
        self::METHOD_CASH, self::METHOD_BANK_TRANSFER, self::METHOD_VA,
        self::METHOD_GATEWAY, self::METHOD_WALLET, self::METHOD_MANUAL,
    ];

    public const METHOD_LABELS = [
        self::METHOD_CASH => 'Tunai',
        self::METHOD_BANK_TRANSFER => 'Transfer Bank',
        self::METHOD_VA => 'Virtual Account',
        self::METHOD_GATEWAY => 'Payment Gateway',
        self::METHOD_WALLET => 'Wallet',
        self::METHOD_MANUAL => 'Transfer Manual',
    ];

    /** Metode yang butuh verifikasi petugas sebelum dianggap lunas (§21). */
    public const MANUAL_METHODS = [self::METHOD_MANUAL, self::METHOD_BANK_TRANSFER, self::METHOD_VA];

    protected $casts = [
        'customer_id' => 'integer',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(FinanceBilling::class, 'billing_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceCashAccount::class, 'cash_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccount::class, 'bank_account_id');
    }

    public function cashierSession(): BelongsTo
    {
        return $this->belongsTo(FinanceCashierSession::class, 'cashier_session_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(FinanceReceipt::class, 'payment_id')->where('is_cancelled', false);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(FinanceRefund::class, 'payment_id');
    }

    /**
     * Alokasi ke invoice (§15–§18 Phase 2). Satu pembayaran bisa menutup banyak
     * invoice; `invoice.paid_amount` = SUM(amount) per invoice.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(FinancePaymentAllocation::class, 'payment_id');
    }

    public function gatewayTransactions(): HasMany
    {
        return $this->hasMany(FinancePaymentGatewayTransaction::class, 'payment_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(FinancePaymentVerification::class, 'payment_id');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PAID, self::STATUS_PARTIAL, self::STATUS_REFUNDED]);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isConfirmed(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_PARTIAL], true);
    }

    public function methodLabel(): string
    {
        return self::METHOD_LABELS[$this->method] ?? (string) $this->method;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_PAYMENT';
    }
}
