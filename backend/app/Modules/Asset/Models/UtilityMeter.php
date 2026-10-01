<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `utility_meters` — modul Asset & Sarpras.
 */
class UtilityMeter extends Model
{
    use BelongsToSchool;

    protected $table = 'utility_meters';

    protected $fillable = [
        'facility_id',
        'location_id',
        'meter_type',
        'meter_number',
        'unit',
        'multiplier',
        'current_reading',
        'last_reading',
        'last_reading_at',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'meter_type' => \App\Modules\Asset\Domain\Enums\UtilityMeterType::class,
        'multiplier' => 'decimal:2',
        'current_reading' => 'decimal:2',
        'last_reading' => 'decimal:2',
        'last_reading_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function facility(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function readings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UtilityReading::class, 'meter_id');
    }
}
