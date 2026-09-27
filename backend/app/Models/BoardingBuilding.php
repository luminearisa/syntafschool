<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Gedung di dalam satu asrama (§5). Opsional: asrama kecil boleh langsung
 * punya lantai tanpa gedung perantara, karena `boarding_placements.building_id`
 * nullable dan kamar hanya terikat ke lantai.
 */
class BoardingBuilding extends BaseModel
{
    use LogsBoardingActivity, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUSES = [
        self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_MAINTENANCE,
    ];

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Aktif',
        self::STATUS_INACTIVE => 'Tidak Aktif',
        self::STATUS_MAINTENANCE => 'Pemeliharaan',
    ];

    protected $casts = [
        'floor_count' => 'integer',
    ];

    public function dormitory(): BelongsTo
    {
        return $this->belongsTo(BoardingDormitory::class, 'dormitory_id');
    }

    public function floors(): HasMany
    {
        return $this->hasMany(BoardingFloor::class, 'building_id')->orderBy('floor_number');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(BoardingPlacement::class, 'building_id');
    }

    public function musyrifs(): HasMany
    {
        return $this->hasMany(BoardingMusyrif::class, 'building_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Kamar pada seluruh lantai gedung ini — relasi lewat lantai, bukan FK
     * langsung, karena tabel kamar tidak menyimpan `building_id`.
     *
     * @return Builder<BoardingRoom>
     */
    public function roomQuery(): Builder
    {
        return BoardingRoom::query()
            ->whereIn('floor_id', $this->floors()->select('id'));
    }

    public function currentOccupancy(): int
    {
        return $this->placements()
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->count();
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_BUILDING';
    }
}
