<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\PoAmendmentStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versi PO (amandemen non-destruktif). Tabel: `proc_purchase_order_versions`.
 *
 * `snapshot` menyimpan seluruh isi PO pada versi tersebut sehingga histori
 * tetap utuh; `changed_fields`, `old_values`, `new_values` menjelaskan apa yang
 * berubah dan mengapa (§20).
 */
class PurchaseOrderVersion extends ProcurementModel
{
    protected $table = 'proc_purchase_order_versions';

    protected $casts = [
        'snapshot' => 'array',
        'changed_fields' => 'array',
        'old_values' => 'array',
        'new_values' => 'array',
        'version' => 'integer',
        'is_material' => 'boolean',
        'status' => PoAmendmentStatus::class,
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
