<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jadwal kegiatan asrama (§19).
 *
 * Satu baris = satu slot pada satu hari (1 = Senin .. 7 = Minggu). Ruang
 * lingkupnya bertingkat: tanpa `dormitory_id` dan `group_id` jadwal berlaku
 * global; dengan `dormitory_id` hanya untuk asrama itu; dengan `group_id`
 * hanya untuk kelompok pengasuhan itu (halaqah per kelompok, misalnya).
 *
 * Jadwal adalah dasar perhitungan telat (`late_after_minutes` kegiatan) dan
 * dasar rekap "siapa belum diabsen" — karena itu mengedit jamnya mengubah
 * interpretasi absensi hari itu, dan dicatat di audit.
 */
class BoardingActivitySchedule extends BaseModel
{
    use LogsBoardingActivity;

    public const DAY_MONDAY = 1;

    public const DAY_TUESDAY = 2;

    public const DAY_WEDNESDAY = 3;

    public const DAY_THURSDAY = 4;

    public const DAY_FRIDAY = 5;

    public const DAY_SATURDAY = 6;

    public const DAY_SUNDAY = 7;

    /** 1 = Senin, mengikuti `Carbon::dayOfWeekIso`. */
    public const DAYS = [
        self::DAY_MONDAY, self::DAY_TUESDAY, self::DAY_WEDNESDAY, self::DAY_THURSDAY,
        self::DAY_FRIDAY, self::DAY_SATURDAY, self::DAY_SUNDAY,
    ];

    public const DAY_LABELS = [
        self::DAY_MONDAY => 'Senin',
        self::DAY_TUESDAY => 'Selasa',
        self::DAY_WEDNESDAY => 'Rabu',
        self::DAY_THURSDAY => 'Kamis',
        self::DAY_FRIDAY => 'Jumat',
        self::DAY_SATURDAY => 'Sabtu',
        self::DAY_SUNDAY => 'Minggu',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'effective_start' => 'date',
        'effective_end' => 'date',
        'is_active' => 'boolean',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(BoardingActivity::class, 'activity_id');
    }

    public function dormitory(): BelongsTo
    {
        return $this->belongsTo(BoardingDormitory::class, 'dormitory_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BoardingGroup::class, 'group_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(BoardingAttendance::class, 'schedule_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOnDay(Builder $query, ?int $dayOfWeek): Builder
    {
        return $dayOfWeek === null ? $query : $query->where('day_of_week', $dayOfWeek);
    }

    public function scopeForDormitory(Builder $query, ?int $dormitoryId): Builder
    {
        return $dormitoryId === null ? $query : $query->where('dormitory_id', $dormitoryId);
    }

    public function scopeForGroup(Builder $query, ?int $groupId): Builder
    {
        return $groupId === null ? $query : $query->where('group_id', $groupId);
    }

    /**
     * Jadwal yang sedang berlaku pada tanggal tertentu (efektif whole-period
     * bila kolom efektivnya kosong).
     */
    public function scopeEffectiveOn(Builder $query, ?string $date = null): Builder
    {
        $date ??= now()->toDateString();

        return $query->active()->where(function (Builder $q) use ($date): void {
            $q->whereNull('effective_start')->orWhere('effective_start', '<=', $date);
        })->where(function (Builder $q) use ($date): void {
            $q->whereNull('effective_end')->orWhere('effective_end', '>=', $date);
        });
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function dayLabel(): string
    {
        return self::DAY_LABELS[$this->day_of_week] ?? (string) $this->day_of_week;
    }

    /**
     * Batas masih dikatakan tepat waktu: jam mulai + ambang telat kegiatan (§25).
     * Null bila kegiatan memang tidak menghitung keterlambatan.
     */
    public function lateCutoff(): ?string
    {
        $minutes = $this->activity?->late_after_minutes;

        if ($minutes === null || $this->start_time === null) {
            return null;
        }

        return $this->start_time->copy()
            ->addMinutes((int) $minutes)
            ->format('H:i:s');
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_ACTIVITY_SCHEDULE';
    }
}
