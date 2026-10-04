<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Langkah persetujuan PO. Tabel: `proc_purchase_order_approvals`. */
class PurchaseOrderApproval extends ProcurementModel
{
    protected $table = 'proc_purchase_order_approvals';

    protected $casts = ['decision_at' => 'datetime'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
