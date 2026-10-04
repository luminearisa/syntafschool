<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\ServiceReceiptStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Penerimaan jasa (service receipt). Tabel: `proc_service_receipts`. */
class ServiceReceipt extends ProcurementModel
{
    use SoftDeletes;

    protected $table = 'proc_service_receipts';

    protected $casts = [
        'service_period_start' => 'date',
        'service_period_end' => 'date',
        'completion_percentage' => 'decimal:2',
        'accepted_at' => 'datetime',
        'status' => ServiceReceiptStatus::class,
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function responsibleOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_officer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceReceiptItem::class, 'service_receipt_id');
    }
}
