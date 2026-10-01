<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `facility_operating_hours` — modul Asset & Sarpras.
 */
class FacilityOperatingHour extends Model
{
    protected $table = 'facility_operating_hours';

    protected $fillable = [
        'facility_id',
        'day_of_week',
        'open_time',
        'close_time',
        'is_closed',
        'notes',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
    ];
}
