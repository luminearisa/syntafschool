<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `facility_utilization_daily` — modul Asset & Sarpras.
 */
class FacilityUtilizationDaily extends Model
{
    use BelongsToSchool;

    protected $table = 'facility_utilization_daily';

    protected $fillable = [
        'facility_id',
        'date',
        'booking_count',
        'usage_minutes',
        'attendee_count',
        'capacity_utilization',
        'peak_hour',
    ];

    protected $casts = [
        'date' => 'date',
        'capacity_utilization' => 'decimal:2',
    ];
}
