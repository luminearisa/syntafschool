<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Harga/lead time item per vendor. Tabel: `proc_item_vendors`. */
class ItemVendor extends ProcurementModel
{
    protected $table = 'proc_item_vendors';

    protected $casts = [
        'last_price' => 'decimal:2',
        'lead_time_days' => 'integer',
        'is_preferred' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
