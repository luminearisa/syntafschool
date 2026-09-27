<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asrama: unit tertinggi struktur fisik boarding (§5, §6).
 *
 * Kolom `gender` adalah batasan penghuni, bukan label tampilan: asrama laki-laki
 * tidak boleh menampung santri perempuan walau kasur masih kosong. Aturan
 * kecocokan gender ditegakkan di service penempatan (`lockForUpdate`), model ini
 * hanya menyediakan penerjemah kode `genders.code` ('L'/'P') ke nilai enum.
 */
class BoardingDormitory extends BaseModel
{
    use LogsBoardingActivity, SoftDeletes;

    public const GENDER_MALE = 'male';

    public const GENDER_FEMALE = 'female';

    public const GENDER_MIXED = 'mixed';

    public const GENDERS = [self::GENDER_MALE, self::GENDER_FEMALE, self::GENDER_MIXED];

    /**
     * Penerjemah enum asrama ke kode gender master (`genders.code`).
     * `mixed` sengaja tidak punya padanan: ia diterima semua.
     */
    public const GENDER_CODES = [
        self::GENDER_MALE => 'L',
        self::GENDER_FEMALE => 'P',
    ];

    public const GENDER_LABELS = [
        self::GENDER_MALE => 'Laki-laki',
        self::GENDER_FEMALE => 'Perempuan',
        self::GENDER_MIXED => 'Campuran',
    ];

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
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function buildings(): HasMany
    {
        return $this->hasMany(BoardingBuilding::class, 'dormitory_id');
    }

    /**
     * Kamar di asrama ini. `boarding_rooms.dormitory_id` adalah denormalisasi
     * dari bagunan/lantai, jadi daftar kamar tidak perlu tiga join (§5).
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(BoardingRoom::class, 'dormitory_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(BoardingPlacement::class, 'dormitory_id');
    }

    public function activePlacements(): HasMany
    {
        return $this->placements()->where('status', BoardingPlacement::STATUS_ACTIVE);
    }

    public function musyrifs(): HasMany
    {
        return $this->hasMany(BoardingMusyrif::class, 'dormitory_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(BoardingGroup::class, 'dormitory_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(BoardingActivitySchedule::class, 'dormitory_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('is_active', true);
    }

    public function scopeForGender(Builder $query, ?string $gender): Builder
    {
        if ($gender === null || $gender === '') {
            return $query;
        }

        return $query->whereIn('gender', [$gender, self::GENDER_MIXED]);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && (bool) $this->is_active;
    }

    /**
     * Apakah santri berkode gender (`genders.code`: L/P) boleh menempati asrama ini.
     */
    public function acceptsGender(?string $genderCode): bool
    {
        if ($this->gender === self::GENDER_MIXED || $genderCode === null || $genderCode === '') {
            return $this->gender === self::GENDER_MIXED;
        }

        return (self::GENDER_CODES[$this->gender] ?? null) === strtoupper($genderCode);
    }

    public function genderLabel(): string
    {
        return self::GENDER_LABELS[$this->gender] ?? (string) $this->gender;
    }

    /** Santri aktif yang menempati asrama ini (dipakai dashboard & kapasitas). */
    public function currentOccupancy(): int
    {
        return $this->activePlacements()->count();
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_DORMITORY';
    }
}
