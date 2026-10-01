<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `building_floors` — modul Asset & Sarpras.
 */
class BuildingFloor extends Model
{
    protected $table = 'building_floors';

    protected $fillable = [
        'building_id',
        'level',
        'name',
        'area_m2',
        'status',
        'notes',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\OperationalStatus::class,
        'area_m2' => 'decimal:2',
    ];

    public function building(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function locations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Location::class);
    }
}
