<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleEquipment extends Model
{

    protected $table = 'vehicle_equipment';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'vehicle_id' => 'integer',
        'quantity' => 'integer',
        'mandatory' => 'boolean',
        'critical' => 'boolean',
        'expiry_date' => 'date',
        'last_checked_at' => 'date',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleProfile::class, 'vehicle_id');
    }
}
