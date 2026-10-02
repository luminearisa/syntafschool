<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PassengerTransfer extends Model
{

    protected $table = 'passenger_transfers';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'from_vehicle_id' => 'integer',
        'to_vehicle_id' => 'integer',
        'from_driver_profile_id' => 'integer',
        'to_driver_profile_id' => 'integer',
        'passenger_count' => 'integer',
        'transferred_at' => 'datetime',
        'authorized_by' => 'integer',
        'passenger_ids' => 'array',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
}
