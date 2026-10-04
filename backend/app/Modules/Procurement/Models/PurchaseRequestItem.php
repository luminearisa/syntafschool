<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\Enums\ProcurementItemType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris Purchase Request. Tabel: `proc_purchase_request_items`. */
class PurchaseRequestItem extends ProcurementModel
{
    protected $table = 'proc_purchase_request_items';

    protected $casts = [
        'quantity' => 'decimal:4',
        'estimated_unit_price' => 'decimal:2',
        'estimated_total' => 'decimal:2',
        'required_date' => 'date',
        'is_asset' => 'boolean',
        'is_inventory' => 'boolean',
        'item_type' => ProcurementItemType::class,
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function preferredVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'preferred_vendor_id');
    }
}
