<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan kinerja vendor per periode (scorecard). Tabel: `proc_vendor_performance_records`.
 *
 * Nilai dihitung dari data faktual (PO, penerimaan, inspeksi, invoice) oleh
 * `VendorPerformanceService` — bukan masukan manual, agar dapat diaudit.
 */
class VendorPerformanceRecord extends ProcurementModel
{
    protected $table = 'proc_vendor_performance_records';

    protected $casts = [
        'total_ordered_qty' => 'decimal:4',
        'total_received_qty' => 'decimal:4',
        'rejected_qty' => 'decimal:4',
        'avg_delay_days' => 'decimal:2',
        'response_time_hours' => 'decimal:2',
        'on_time_rate' => 'decimal:2',
        'quality_rate' => 'decimal:2',
        'fulfillment_rate' => 'decimal:2',
        'invoice_accuracy_rate' => 'decimal:2',
        'score' => 'decimal:2',
        'computed_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
