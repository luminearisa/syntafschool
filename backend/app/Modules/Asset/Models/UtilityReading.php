<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `utility_readings` — modul Asset & Sarpras.
 */
class UtilityReading extends Model
{
    use BelongsToSchool;

    protected $table = 'utility_readings';

    protected $fillable = [
        'meter_id',
        'reading',
        'reading_at',
        'consumption',
        'unit_cost',
        'total_cost',
        'recorded_by',
        'finance_reference',
        'notes',
    ];

    protected $casts = [
        'reading' => 'decimal:2',
        'reading_at' => 'datetime',
        'consumption' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function meter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UtilityMeter::class, 'meter_id');
    }
}
