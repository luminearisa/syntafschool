<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sesi kalibrasi/moderasi penilai (menyamakan standar antar supervisor). */
class SupCalibrationSession extends BaseModel
{
    protected $table = 'sup_calibration_sessions';

    protected $casts = [
        'held_at' => 'datetime',
    ];

    public function scores(): HasMany
    {
        return $this->hasMany(SupCalibrationScore::class, 'calibration_session_id');
    }
}
