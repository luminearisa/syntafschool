<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penautan PR ke peristiwa pengadaan. Tabel: `proc_event_requests`. */
class ProcurementEventRequest extends ProcurementModel
{
    protected $table = 'proc_event_requests';

    public function event(): BelongsTo
    {
        return $this->belongsTo(ProcurementEvent::class, 'event_id');
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }
}
