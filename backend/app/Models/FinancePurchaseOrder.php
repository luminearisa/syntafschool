<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase order (§38). Penerimaan barang memakai Inventory module bila ada —
 * Finance hanya menangani nilai keuangannya (§37, §63).
 */
class FinancePurchaseOrder extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_ORDERED,
        self::STATUS_PARTIALLY_RECEIVED, self::STATUS_RECEIVED, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SUBMITTED => 'Diajukan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_ORDERED => 'Dipesan',
        self::STATUS_PARTIALLY_RECEIVED => 'Diterima Sebagian',
        self::STATUS_RECEIVED => 'Diterima',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    protected $casts = [
        'po_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FinanceVendor::class, 'vendor_id');
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(FinancePurchaseRequest::class, 'purchase_request_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinancePurchaseOrderItem::class, 'purchase_order_id');
    }

    public function vendorInvoices(): HasMany
    {
        return $this->hasMany(FinanceVendorInvoice::class, 'purchase_order_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_ORDERED, self::STATUS_PARTIALLY_RECEIVED,
        ]);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_PURCHASE_ORDER';
    }
}
