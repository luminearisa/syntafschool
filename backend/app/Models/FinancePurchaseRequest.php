<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase request (§37) — pengajuan pengadaan sebelum jadi PO.
 */
class FinancePurchaseRequest extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'cancelled'];

    protected $casts = [
        'needed_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinancePurchaseRequestItem::class, 'purchase_request_id');
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_PURCHASE_REQUEST';
    }
}
