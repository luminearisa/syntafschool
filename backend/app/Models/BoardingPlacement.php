<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penempatan santri pada satu kamar/tempat tidur (§7, §9, §11).
 *
 * Placement lama TIDAK dihapus saat pindah kamar: ia ditutup
 * (`status = inactive` + `end_reason` + `replaced_by_placement_id`) sehingga
 * riwayat tempat tinggal santri tetap terbaca penuh. Karena itu "di mana santri
 * sekarang" selalu berarti `status = active`, bukan baris terbaru.
 *
 * Aturan satu placement aktif per santri, satu santri per bed, dan occupancy
 * <= capacity ditegakkan di service dengan `DB::transaction` + `lockForUpdate`.
 */
class BoardingPlacement extends BaseModel
{
    use LogsBoardingActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Aktif',
        self::STATUS_INACTIVE => 'Selesai',
    ];

    /** Alasan penutupan placement (§11): jenis mutasi/outcome dibedakan di sini. */
    public const END_REASON_TRANSFER_ROOM = 'transfer_room';

    public const END_REASON_TRANSFER_DORM = 'transfer_dorm';

    public const END_REASON_TRANSFER_BED = 'transfer_bed';

    public const END_REASON_GRADUATED = 'graduated';

    public const END_REASON_DROPPED_OUT = 'dropped_out';

    public const END_REASON_CANCELLED = 'cancelled';

    public const END_REASON_OTHER = 'other';

    public const END_REASONS = [
        self::END_REASON_TRANSFER_ROOM, self::END_REASON_TRANSFER_DORM,
        self::END_REASON_TRANSFER_BED, self::END_REASON_GRADUATED,
        self::END_REASON_DROPPED_OUT, self::END_REASON_CANCELLED,
        self::END_REASON_OTHER,
    ];

    public const END_REASON_LABELS = [
        self::END_REASON_TRANSFER_ROOM => 'Pindah kamar',
        self::END_REASON_TRANSFER_DORM => 'Pindah asrama',
        self::END_REASON_TRANSFER_BED => 'Pindah tempat tidur',
        self::END_REASON_GRADUATED => 'Lulus',
        self::END_REASON_DROPPED_OUT => 'Keluar',
        self::END_REASON_CANCELLED => 'Dibatalkan',
        self::END_REASON_OTHER => 'Lainnya',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function dormitory(): BelongsTo
    {
        return $this->belongsTo(BoardingDormitory::class, 'dormitory_id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(BoardingBuilding::class, 'building_id');
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(BoardingFloor::class, 'floor_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(BoardingRoom::class, 'room_id');
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(BoardingBed::class, 'bed_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** Placement lanjutan hasil mutasi (§11). */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_placement_id');
    }

    /**
     * Baris-baris lama yang ditutup demi placement ini — kebalikan dari
     * `replacedBy()`, dipakai untuk menelusuri rantai mutasi dari ujung aktif.
     */
    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_placement_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    public function scopeForDormitory(Builder $query, ?int $dormitoryId): Builder
    {
        return $dormitoryId === null ? $query : $query->where('dormitory_id', $dormitoryId);
    }

    public function scopeForBuilding(Builder $query, ?int $buildingId): Builder
    {
        return $buildingId === null ? $query : $query->where('building_id', $buildingId);
    }

    public function scopeForFloor(Builder $query, ?int $floorId): Builder
    {
        return $floorId === null ? $query : $query->where('floor_id', $floorId);
    }

    public function scopeForRoom(Builder $query, ?int $roomId): Builder
    {
        return $roomId === null ? $query : $query->where('room_id', $roomId);
    }

    public function scopeForBed(Builder $query, ?int $bedId): Builder
    {
        return $bedId === null ? $query : $query->where('bed_id', $bedId);
    }

    /** Placement yang masih berlaku pada tanggal tertentu (history queryable). */
    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->where('start_date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Label singkat untuk daftar & notifikasi: "ASM-01 / A101 / 03".
     */
    public function label(): string
    {
        $parts = array_filter([
            $this->dormitory?->code,
            $this->room?->code,
            $this->bed?->bed_number,
        ]);

        return $parts === [] ? '(tanpa lokasi)' : implode(' / ', $parts);
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_PLACEMENT';
    }
}
