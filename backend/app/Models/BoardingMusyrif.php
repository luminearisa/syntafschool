<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan pengasuh (musyrif/musyrifah) pada satu area (§8).
 *
 * Satu baris = satu tanggung jawab: boleh berupa kepala asrama (hanya
 * `dormitory_id`), wali kamar (sampai `room_id`), atau koordinator. Area yang
 * lebih spesifik mempersempit area di atasnya, dan seluruh baris aktif milik
 * satu karyawan digabung (union) oleh `BoardingScopeService` menjadi hak akses.
 *
 * Jabatan `musyrifah` dipakai untuk pengasuh perempuan — bukan sekadar label:
 * pencocokan gender santri binaan mengacu ke sini.
 */
class BoardingMusyrif extends BaseModel
{
    use LogsBoardingActivity;

    public const POSITION_KEPALA_ASRAMA = 'kepala_asrama';

    public const POSITION_MUSYRIF = 'musyrif';

    public const POSITION_MUSYRIFAH = 'musyrifah';

    public const POSITION_WALI_KAMAR = 'wali_kamar';

    public const POSITION_KOORDINATOR = 'koordinator';

    public const POSITIONS = [
        self::POSITION_KEPALA_ASRAMA, self::POSITION_MUSYRIF, self::POSITION_MUSYRIFAH,
        self::POSITION_WALI_KAMAR, self::POSITION_KOORDINATOR,
    ];

    public const POSITION_LABELS = [
        self::POSITION_KEPALA_ASRAMA => 'Kepala Asrama',
        self::POSITION_MUSYRIF => 'Musyrif',
        self::POSITION_MUSYRIFAH => 'Musyrifah',
        self::POSITION_WALI_KAMAR => 'Wali Kamar',
        self::POSITION_KOORDINATOR => 'Koordinator',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Aktif',
        self::STATUS_INACTIVE => 'Selesai',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function dormitory(): BelongsTo
    {
        return $this->belongsTo(BoardingDormitory::class, 'dormitory_id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(BoardingBuilding::class, 'building_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(BoardingRoom::class, 'room_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForEmployee(Builder $query, ?int $employeeId): Builder
    {
        return $employeeId === null ? $query : $query->where('employee_id', $employeeId);
    }

    /** Penugasan yang masih berjalan pada tanggal tertentu. */
    public function scopeRunningOn(Builder $query, ?string $date = null): Builder
    {
        $date ??= now()->toDateString();

        return $query->active()
            ->where('start_date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function positionLabel(): string
    {
        return self::POSITION_LABELS[$this->position] ?? $this->position;
    }

    /**
     * Area tanggung jawab untuk teks tampilan: "Asrama ASM-01 · Kamar A101".
     */
    public function areaLabel(): string
    {
        $parts = array_filter([
            $this->dormitory?->name,
            $this->room?->name,
        ]);

        return $parts === [] ? '(seluruh asrama)' : implode(' · ', $parts);
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_MUSYRIF';
    }
}
