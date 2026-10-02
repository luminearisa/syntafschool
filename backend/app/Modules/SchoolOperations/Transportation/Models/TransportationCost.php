<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportationCost extends Model
{

    protected $table = 'transportation_costs';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'vehicle_id' => 'integer',
        'driver_profile_id' => 'integer',
        'route_id' => 'integer',
        'cost_date' => 'date',
        'amount' => 'decimal:2',
        'quantity' => 'decimal:2',
        'vendor_id' => 'integer',
        'billable' => 'boolean',
        'posted_at' => 'datetime',
        'recorded_by' => 'integer',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleProfile::class, 'vehicle_id');
    }
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'route_id');
    }
}
