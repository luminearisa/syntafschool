<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\InvoiceStatus;
use App\Modules\Procurement\Domain\Enums\MatchStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Invoice vendor. Tabel: `proc_vendor_invoices`.
 *
 * `paid_amount` hanyalah **cermin** status pembayaran dari Accounting; modul
 * Procurement tidak menyimpan ledger pembayaran sendiri (§34, §52.11).
 */
class VendorInvoice extends ProcurementModel
{
    use SoftDeletes;

    protected $table = 'proc_vendor_invoices';

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'status' => InvoiceStatus::class,
        'match_status' => MatchStatus::class,
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VendorInvoiceItem::class, 'vendor_invoice_id');
    }

    public function matchResults(): HasMany
    {
        return $this->hasMany(InvoiceMatchResult::class, 'vendor_invoice_id');
    }

    public function latestMatch(): HasOne
    {
        return $this->hasOne(InvoiceMatchResult::class, 'vendor_invoice_id')->latestOfMany();
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(InvoiceException::class, 'vendor_invoice_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(ProcurementIntegration::class, 'subject_id')
            ->where('subject_type', self::class);
    }

    /** @param Builder<self> $query */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereNotIn('status', [InvoiceStatus::POSTED->value, InvoiceStatus::CANCELLED->value]);
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && (float) $this->paid_amount < (float) $this->total;
    }
}
