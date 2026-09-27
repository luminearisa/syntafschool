<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lantai pada satu gedung (§5). Nomor lantai unik per gedung — aturan itu
 * dijamin index `['building_id', 'floor_number']` di database.
 */
class BoardingFloor extends BaseModel
{
    use LogsBoardingActivity;

    protected $casts = [
        'floor_number' => 'integer',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(BoardingBuilding::class, 'building_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(BoardingRoom::class, 'floor_id')->orderBy('code');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(BoardingPlacement::class, 'floor_id');
    }

    /**
     * Label untuk breadcrumb & laporan: "Lantai 2" bila nama tidak diisi.
     */
    public function displayName(): string
    {
        return $this->name ?: ('Lantai '.$this->floor_number);
    }

    public function currentOccupancy(): int
    {
        return $this->placements()
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->count();
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_FLOOR';
    }
}
