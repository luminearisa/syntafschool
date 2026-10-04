<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemilihan vendor (keputusan + alasan). Tabel: `proc_vendor_selections`.
 *
 * Menyimpan `rationale` dan `rejection_summary` agar sistem dapat menjawab
 * "mengapa vendor ini dipilih?" (§16).
 */
class VendorSelection extends ProcurementModel
{
    protected $table = 'proc_vendor_selections';

    protected $casts = [
        'rejection_summary' => 'array',
        'approved_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(ProcurementEvent::class, 'event_id');
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function selectedQuotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'selected_quotation_id');
    }

    public function selectedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'selected_vendor_id');
    }

    public function alternativeVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'alternative_vendor_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
