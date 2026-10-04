<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\ProcurementEventStatus;
use App\Modules\Procurement\Domain\Enums\ProcurementMethod;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Peristiwa pengadaan gabungan (konsolidasi beberapa PR). Tabel: `proc_events`. */
class ProcurementEvent extends ProcurementModel
{
    protected $table = 'proc_events';

    protected $casts = [
        'estimated_total' => 'decimal:2',
        'status' => ProcurementEventStatus::class,
        'method' => ProcurementMethod::class,
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function requests(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseRequest::class, 'proc_event_requests', 'event_id', 'purchase_request_id')
            ->withTimestamps();
    }

    public function rfqs(): HasMany
    {
        return $this->hasMany(Rfq::class, 'event_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'event_id');
    }
}
