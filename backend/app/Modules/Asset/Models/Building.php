<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `buildings` — modul Asset & Sarpras.
 */
class Building extends Model
{
    use BelongsToSchool;

    protected $table = 'buildings';

    protected $fillable = [
        'campus_id',
        'code',
        'name',
        'building_type',
        'year_built',
        'floors_count',
        'area_m2',
        'status',
        'notes',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\OperationalStatus::class,
        'area_m2' => 'decimal:2',
    ];

    public function campus(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function floors(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BuildingFloor::class);
    }

    public function locations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Location::class);
    }
}
