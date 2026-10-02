<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;

class TransportationVehicleType extends Model
{

    protected $table = 'transportation_vehicle_types';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'default_passenger_capacity' => 'integer',
        'default_standing_capacity' => 'integer',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

}
