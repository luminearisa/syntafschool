<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\MatchStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hasil 3-way matching. Tabel: `proc_invoice_match_results`. */
class InvoiceMatchResult extends ProcurementModel
{
    protected $table = 'proc_invoice_match_results';

    protected $casts = [
        'match_status' => MatchStatus::class,
        'quantity_variance' => 'decimal:4',
        'price_variance' => 'decimal:4',
        'amount_variance' => 'decimal:2',
        'tolerance_snapshot' => 'array',
        'details' => 'array',
        'matched_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class, 'vendor_invoice_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
