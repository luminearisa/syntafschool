<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kamar asrama — satuan penempatan yang dipakai sehari-hari (§5, §7).
 *
 * `dormitory_id` disimpan redundan bersama `floor_id` supaya filter "kamar di
 * asrama X" tidak perlu tiga join; nilai keduanya harus sejalan, dan itu
 * ditegakkan di service saat kamar dibuat/dipindah.
 *
 * `room_type = isolation` dipakai layanan status (§42): santri dengan catatan
 * kesehatan aktif di kamar isolasi dilaporkan `isolation`, bukan `sick`.
 */
class BoardingRoom extends BaseModel
{
    use LogsBoardingActivity, SoftDeletes;

    public const GENDER_MALE = 'male';

    public const GENDER_FEMALE = 'female';

    public const GENDER_MIXED = 'mixed';

    public const GENDERS = [self::GENDER_MALE, self::GENDER_FEMALE, self::GENDER_MIXED];

    /** Penerjemah enum kamar ke kode gender master (`genders.code`). */
    public const GENDER_CODES = [
        self::GENDER_MALE => 'L',
        self::GENDER_FEMALE => 'P',
    ];

    public const GENDER_LABELS = [
        self::GENDER_MALE => 'Laki-laki',
        self::GENDER_FEMALE => 'Perempuan',
        self::GENDER_MIXED => 'Campuran',
    ];

    public const TYPE_STANDARD = 'standard';

    public const TYPE_PREMIUM = 'premium';

    public const TYPE_ISOLATION = 'isolation';

    public const TYPE_STAFF = 'staff';

    public const TYPE_TEMPORARY = 'temporary';

    public const ROOM_TYPES = [
        self::TYPE_STANDARD, self::TYPE_PREMIUM, self::TYPE_ISOLATION,
        self::TYPE_STAFF, self::TYPE_TEMPORARY,
    ];

    public const ROOM_TYPE_LABELS = [
        self::TYPE_STANDARD => 'Standar',
        self::TYPE_PREMIUM => 'Premium',
        self::TYPE_ISOLATION => 'Isolasi',
        self::TYPE_STAFF => 'Pengasuh',
        self::TYPE_TEMPORARY => 'Sementara',
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
    ];

    public function floor(): BelongsTo
    {
        return $this->belongsTo(BoardingFloor::class, 'floor_id');
    }

    public function dormitory(): BelongsTo
    {
        return $this->belongsTo(BoardingDormitory::class, 'dormitory_id');
    }

    public function beds(): HasMany
    {
        return $this->hasMany(BoardingBed::class, 'room_id')->orderBy('bed_number');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(BoardingPlacement::class, 'room_id');
    }

    public function musyrifs(): HasMany
    {
        return $this->hasMany(BoardingMusyrif::class, 'room_id');
    }

    /** Kamar yang boleh dihuni: status aktif (bukan maintenance/nonaktif). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForGender(Builder $query, ?string $gender): Builder
    {
        if ($gender === null || $gender === '') {
            return $query;
        }

        return $query->whereIn('gender', [$gender, self::GENDER_MIXED]);
    }

    public function scopeOfType(Builder $query, ?string $roomType): Builder
    {
        if ($roomType === null || $roomType === '') {
            return $query;
        }

        return $query->where('room_type', $roomType);
    }

    /**
     * Kamar yang masih punya tempat tidur tersedia (status bed `available`),
     * dibatasi kapasitas kamar — dua-duanya harus dilihat agar daftar "kamar
     * kosong" tidak menawarkan kamar penuh ber-bed 'aktif'.
     */
    public function scopeWithAvailability(Builder $query): Builder
    {
        return $query->active()
            ->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('boarding_beds')
                    ->whereColumn('boarding_beds.room_id', 'boarding_rooms.id')
                    ->where('boarding_beds.status', BoardingBed::STATUS_AVAILABLE);
            })
            ->whereRaw(
                'boarding_rooms.capacity > (select count(*) from boarding_placements'.
                " where boarding_placements.room_id = boarding_rooms.id and boarding_placements.status = ?)",
                [BoardingPlacement::STATUS_ACTIVE],
            );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isIsolation(): bool
    {
        return $this->room_type === self::TYPE_ISOLATION;
    }

    public function genderLabel(): string
    {
        return self::GENDER_LABELS[$this->gender] ?? (string) $this->gender;
    }

    /**
     * Penghuni sekarang = placement berstatus aktif (§9).
     */
    public function currentOccupancy(): int
    {
        return $this->placements()->where('status', BoardingPlacement::STATUS_ACTIVE)->count();
    }

    public function remainingCapacity(): int
    {
        return max(0, (int) $this->capacity - $this->currentOccupancy());
    }

    public function isFull(): bool
    {
        return $this->remainingCapacity() <= 0;
    }

    /**
     * Apakah santri berkode gender (`genders.code`: L/P) boleh masuk kamar ini.
     */
    public function acceptsGender(?string $genderCode): bool
    {
        if ($this->gender === self::GENDER_MIXED) {
            return true;
        }

        if ($genderCode === null || $genderCode === '') {
            return false;
        }

        return (self::GENDER_CODES[$this->gender] ?? null) === strtoupper($genderCode);
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_ROOM';
    }
}
