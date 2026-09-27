<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tempat tidur: unit terkecil penempatan (§5).
 *
 * Satu bed hanya boleh punya satu placement aktif — dijaga oleh relasi
 * `activePlacement()` dan oleh `lockForUpdate` di service mutasi (§10), bukan
 * oleh constraint di tabel karena kolom status ada di tabel placement.
 *
 * Bed boleh berstatus `available` sementara penghuninya sedang izin keluar:
 * status bed menggambarkan ketersediaan fisik, kehadiran harian dibaca dari
 * absensi/status (§42).
 */
class BoardingBed extends BaseModel
{
    use LogsBoardingActivity;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_OCCUPIED = 'occupied';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_AVAILABLE, self::STATUS_OCCUPIED,
        self::STATUS_MAINTENANCE, self::STATUS_INACTIVE,
    ];

    public const STATUS_LABELS = [
        self::STATUS_AVAILABLE => 'Tersedia',
        self::STATUS_OCCUPIED => 'Terisi',
        self::STATUS_MAINTENANCE => 'Pemeliharaan',
        self::STATUS_INACTIVE => 'Tidak Aktif',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(BoardingRoom::class, 'room_id');
    }

    /**
     * Penempatan aktif sekarang (eager-loadable: `BoardingBed::with('activePlacement')`).
     */
    public function activePlacement(): HasOne
    {
        return $this->hasOne(BoardingPlacement::class, 'bed_id')
            ->where('status', BoardingPlacement::STATUS_ACTIVE);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(BoardingPlacement::class, 'bed_id');
    }

    /** Bed yang boleh ditempati: status `available`. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    public function scopeInRoom(Builder $query, ?int $roomId): Builder
    {
        return $roomId === null ? $query : $query->where('room_id', $roomId);
    }

    /** Bed yang benar-benar kosong: tersedia dan tidak ada placement aktif. */
    public function scopeVacant(Builder $query): Builder
    {
        return $query->available()->whereDoesntHave('activePlacement');
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    /**
     * Penghuni aktif, memakai relasi yang sudah di-load bila ada supaya daftar
     * denah kamar tidak memicu N+1.
     */
    public function occupant(): ?BoardingPlacement
    {
        return $this->relationLoaded('activePlacement')
            ? $this->activePlacement
            : $this->activePlacement()->first();
    }

    public function isOccupied(): bool
    {
        return $this->occupant() !== null;
    }

    public function displayName(): string
    {
        return $this->label ?: ('Bed '.$this->bed_number);
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_BED';
    }
}
