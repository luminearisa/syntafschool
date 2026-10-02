<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverLicense extends Model
{

    protected $table = 'driver_licenses';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'driver_profile_id' => 'integer',
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'is_primary' => 'boolean',
        'verified' => 'boolean',
        'verified_by' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }
}
