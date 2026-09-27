<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kegiatan harian asrama (§16, §18): shalat berjamaah, halaqah, makan,
 * belajar malam, olahraga, apel, kebersihan.
 *
 * Himpunan tipe absensi yang sah TIDAK di-hard-code: ia diambil dari
 * `boarding_activity_attendance_types` (pivot) karena Shalat ≠ Halaqah ≠ Makan
 * (§18). `default_attendance_type_id` hanya pilihan awal pada form.
 *
 * `late_after_minutes` menjadi ambang telat: santri yang tercatat setelah
 * jam mulai jadwal + ambang ini ditandai `is_late` (§25).
 */
class BoardingActivity extends BaseModel
{
    use LogsBoardingActivity;

    public const CATEGORY_IBADAH = 'ibadah';

    public const CATEGORY_ACADEMIC = 'academic';

    public const CATEGORY_TAHFIDZ = 'tahfidz';

    public const CATEGORY_MEAL = 'meal';

    public const CATEGORY_CLEANLINESS = 'cleanliness';

    public const CATEGORY_DISCIPLINE = 'discipline';

    public const CATEGORY_SPORTS = 'sports';

    public const CATEGORY_HEALTH = 'health';

    public const CATEGORY_DORMITORY = 'dormitory';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_IBADAH, self::CATEGORY_ACADEMIC, self::CATEGORY_TAHFIDZ,
        self::CATEGORY_MEAL, self::CATEGORY_CLEANLINESS, self::CATEGORY_DISCIPLINE,
        self::CATEGORY_SPORTS, self::CATEGORY_HEALTH, self::CATEGORY_DORMITORY,
        self::CATEGORY_OTHER,
    ];

    public const CATEGORY_LABELS = [
        self::CATEGORY_IBADAH => 'Ibadah',
        self::CATEGORY_ACADEMIC => 'Akademik',
        self::CATEGORY_TAHFIDZ => 'Tahfidz',
        self::CATEGORY_MEAL => 'Makan',
        self::CATEGORY_CLEANLINESS => 'Kebersihan',
        self::CATEGORY_DISCIPLINE => 'Kedisiplinan',
        self::CATEGORY_SPORTS => 'Olahraga',
        self::CATEGORY_HEALTH => 'Kesehatan',
        self::CATEGORY_DORMITORY => 'Asrama',
        self::CATEGORY_OTHER => 'Lainnya',
    ];

    /** Kegiatan yang absensinya wajib diambil (bukan pilihan Sukarela). */
    public const MANDATORY_CATEGORIES = [
        self::CATEGORY_IBADAH, self::CATEGORY_TAHFIDZ, self::CATEGORY_ACADEMIC,
        self::CATEGORY_DISCIPLINE,
    ];

    protected $casts = [
        'late_after_minutes' => 'integer',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function defaultAttendanceType(): BelongsTo
    {
        return $this->belongsTo(BoardingAttendanceType::class, 'default_attendance_type_id');
    }

    /**
     * Tipe absensi yang boleh dipakai pada kegiatan ini (§18), terurut
     * `sort_order` pivot supaya urutannya sama di form maupun laporan.
     */
    public function attendanceTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            BoardingAttendanceType::class,
            'boarding_activity_attendance_types',
            'activity_id',
            'attendance_type_id',
        )
            ->withPivot('sort_order')
            ->orderBy('boarding_activity_attendance_types.sort_order')
            ->orderBy('boarding_attendance_types.id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(BoardingActivitySchedule::class, 'activity_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(BoardingAttendance::class, 'activity_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }

    public function scopeOfCategory(Builder $query, ?string $category): Builder
    {
        return ($category === null || $category === '') ? $query : $query->where('category', $category);
    }

    public function scopeShalat(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_IBADAH);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Kegiatan ini bisa dianggap terlambat? Tanpa ambang, `is_late` tidak pernah
     * dihitung dan kolom TERLAMBAT tidak offered.
     */
    public function hasLateThreshold(): bool
    {
        return $this->late_after_minutes !== null && (int) $this->late_after_minutes > 0;
    }

    /**
     * @return list<int>
     */
    public function attendanceTypeIds(): array
    {
        return $this->attendanceTypes()
            ->pluck('boarding_attendance_types.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_ACTIVITY';
    }
}
