<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Langkah persetujuan PR. Tabel: `proc_purchase_request_approvals`. */
class PurchaseRequestApproval extends ProcurementModel
{
    protected $table = 'proc_purchase_request_approvals';

    protected $casts = ['decision_at' => 'datetime'];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
