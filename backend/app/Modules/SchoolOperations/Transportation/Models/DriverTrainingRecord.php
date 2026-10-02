<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverTrainingRecord extends Model
{

    protected $table = 'driver_training_records';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'driver_profile_id' => 'integer',
        'started_at' => 'date',
        'completed_at' => 'date',
        'score' => 'integer',
        'valid_until' => 'date',
        'recorded_by' => 'integer',
    ];

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }
}
