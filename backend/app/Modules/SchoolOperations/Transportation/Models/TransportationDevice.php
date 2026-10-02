<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;

class TransportationDevice extends Model
{

    protected $table = 'transportation_devices';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'vehicle_id' => 'integer',
        'driver_profile_id' => 'integer',
        'last_sync_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'active' => 'boolean',
        'meta' => 'array',
    ];

}
