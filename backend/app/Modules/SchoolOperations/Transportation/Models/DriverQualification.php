<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverQualification extends Model
{

    protected $table = 'driver_qualifications';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'driver_profile_id' => 'integer',
        'issued_at' => 'date',
        'expiry_date' => 'date',
    ];

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }
}
