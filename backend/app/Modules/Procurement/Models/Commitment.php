<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\Enums\CommitmentStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Komitmen anggaran atas PO/PR. Tabel: `proc_commitments`. */
class Commitment extends ProcurementModel
{
    protected $table = 'proc_commitments';

    protected $casts = [
        'amount' => 'decimal:2',
        'released_at' => 'datetime',
        'status' => CommitmentStatus::class,
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }
}
