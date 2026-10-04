<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\VendorConfirmationStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Konfirmasi vendor atas PO. Tabel: `proc_vendor_confirmations`. */
class VendorConfirmation extends ProcurementModel
{
    protected $table = 'proc_vendor_confirmations';

    protected $casts = [
        'confirmed_at' => 'datetime',
        'revised_delivery_date' => 'date',
        'status' => VendorConfirmationStatus::class,
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
