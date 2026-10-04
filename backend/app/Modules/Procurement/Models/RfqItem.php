<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Baris item RFQ. Tabel: `proc_rfq_items`. */
class RfqItem extends ProcurementModel
{
    protected $table = 'proc_rfq_items';

    protected $casts = [
        'quantity' => 'decimal:4',
        'target_price' => 'decimal:2',
        'required_date' => 'date',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'purchase_request_item_id');
    }

    public function quotationItems(): HasMany
    {
        return $this->hasMany(QuotationItem::class, 'rfq_item_id');
    }
}
