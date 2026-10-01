<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `facility_blackout_periods` — modul Asset & Sarpras.
 */
class FacilityBlackoutPeriod extends Model
{
    use BelongsToSchool;

    protected $table = 'facility_blackout_periods';

    protected $fillable = [
        'facility_id',
        'start_at',
        'end_at',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];
}
