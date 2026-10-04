<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item kontrak (harga kesepakatan). Tabel: `proc_contract_items`. */
class ContractItem extends ProcurementModel
{
    protected $table = 'proc_contract_items';

    protected $casts = [
        'agreed_price' => 'decimal:2',
        'min_quantity' => 'decimal:4',
        'max_quantity' => 'decimal:4',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
