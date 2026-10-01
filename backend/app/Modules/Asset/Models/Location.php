<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Lokasi berjenjang: kampus → gedung → lantai → ruang/area.
 */
class Location extends Model
{
    use BelongsToSchool;

    protected $table = 'locations';

    protected $fillable = [
        'campus_id',
        'building_id',
        'building_floor_id',
        'parent_location_id',
        'code',
        'name',
        'location_type',
        'path',
        'level',
        'capacity',
        'area_m2',
        'floor_label',
        'function',
        'status',
        'responsible_unit_id',
        'manager_user_id',
        'contact',
        'is_bookable',
        'description',
    ];

    protected $casts = [
        'location_type' => \App\Modules\Asset\Domain\Enums\LocationType::class,
        'status' => \App\Modules\Asset\Domain\Enums\OperationalStatus::class,
        'area_m2' => 'decimal:2',
        'is_bookable' => 'boolean',
    ];

    public function campus(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function building(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }

    public function floor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BuildingFloor::class, 'building_floor_id');
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_location_id');
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'parent_location_id');
    }

    public function assets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Asset::class, 'location_id');
    }

    public function facilities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Facility::class, 'location_id');
    }

    public function manager(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'manager_user_id');
    }

    public function responsibleUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Unit::class, 'responsible_unit_id');
    }

    /** Leluhur dari akar hingga induk langsung (untuk breadcrumb dan laporan). */
    public function ancestors(): array
    {
        $chain = [];
        $cursor = $this->parent;

        while ($cursor !== null) {
            $chain[] = $cursor;
            $cursor = $cursor->parent;
        }

        return array_reverse($chain);
    }
}
