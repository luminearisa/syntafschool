<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Purchase Order (PO). Tabel: `proc_purchase_orders`.
 *
 * Harga item adalah **snapshot** saat PO terbit; perubahan harga vendor
 * sesudahnya tidak mengubah PO historis (§18). Amandemen menambah versi baru
 * (§20) dan tidak pernah menimpa histori.
 */
class PurchaseOrder extends ProcurementModel
{
    use SoftDeletes;

    protected $table = 'proc_purchase_orders';

    protected $casts = [
        'po_date' => 'date',
        'expected_delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'current_version' => 'integer',
        'issued_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'status' => PurchaseOrderStatus::class,
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ProcurementEvent::class, 'event_id');
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(VendorSelection::class, 'selection_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PurchaseOrderVersion::class, 'purchase_order_id')->orderBy('version');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PurchaseOrderApproval::class, 'purchase_order_id')->orderBy('step');
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(VendorConfirmation::class, 'purchase_order_id');
    }

    public function latestConfirmation(): HasOne
    {
        return $this->hasOne(VendorConfirmation::class, 'purchase_order_id')->latestOfMany();
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'purchase_order_id');
    }

    public function serviceReceipts(): HasMany
    {
        return $this->hasMany(ServiceReceipt::class, 'purchase_order_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(VendorInvoice::class, 'purchase_order_id');
    }

    /** @param Builder<self> $query */
    public function scopeIssued(Builder $query): void
    {
        $query->whereIn('status', [
            PurchaseOrderStatus::ISSUED->value,
            PurchaseOrderStatus::VENDOR_CONFIRMED->value,
            PurchaseOrderStatus::PARTIALLY_RECEIVED->value,
            PurchaseOrderStatus::FULLY_RECEIVED->value,
        ]);
    }

    /** @param Builder<self> $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.$term.'%';
        $query->where(fn (Builder $q) => $q
            ->where('number', 'like', $like)
            ->orWhereHas('vendor', fn (Builder $v) => $v->where('legal_name', 'like', $like)));
    }

    public function statusEnum(): PurchaseOrderStatus
    {
        return $this->status instanceof PurchaseOrderStatus
            ? $this->status
            : PurchaseOrderStatus::from((string) $this->status);
    }
}
