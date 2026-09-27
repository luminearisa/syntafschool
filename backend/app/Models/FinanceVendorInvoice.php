<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vendor invoice (§35) + Accounts Payable (§36). Invoice vendor yang belum
 * dibayar membentuk hutang; `remaining_amount` per vendor = AP outstanding.
 */
class FinanceVendorInvoice extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_RECEIVED, self::STATUS_PARTIALLY_PAID,
        self::STATUS_PAID, self::STATUS_OVERDUE, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_RECEIVED => 'Diterima',
        self::STATUS_PARTIALLY_PAID => 'Dibayar Sebagian',
        self::STATUS_PAID => 'Lunas',
        self::STATUS_OVERDUE => 'Jatuh Tempo',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FinanceVendor::class, 'vendor_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(FinancePurchaseOrder::class, 'purchase_order_id');
    }

    public function expenseRequest(): BelongsTo
    {
        return $this->belongsTo(FinanceExpenseRequest::class, 'expense_request_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_RECEIVED, self::STATUS_PARTIALLY_PAID, self::STATUS_OVERDUE]);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_VENDOR_INVOICE';
    }
}
