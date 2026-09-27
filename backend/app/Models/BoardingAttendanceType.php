<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master tipe absensi asrama (§17): hadir, sakit, izin, alpha, terlambat,
 * dispensasi, dinas, pulang, tidak diwajibkan.
 *
 * Sengaja TIDAK di-hard-code di kode: pesantren berbeda punya kebiasaan
 * berbeda (dispensasi haid, dinas keluar kota, pulang cepat). Yang dibaca
 * sistem adalah sifatnya — `is_present`, `is_excused`, `requires_note`,
 * `requires_approval` — sementara `category` hanya mengelompokkan perilaku
 * untuk kebutuhan laporan.
 */
class BoardingAttendanceType extends BaseModel
{
    use LogsBoardingActivity;

    public const CODE_HADIR = 'HADIR';

    public const CODE_TIDAK_HADIR = 'TIDAK-HADIR';

    public const CODE_SAKIT = 'SAKIT';

    public const CODE_IZIN = 'IZIN';

    public const CODE_ALPHA = 'ALPHA';

    public const CODE_TERLAMBAT = 'TERLAMBAT';

    public const CODE_DISPENSASI = 'DISPENSASI';

    public const CODE_DINAS = 'DINAS';

    public const CODE_PULANG = 'PULANG';

    public const CODE_TIDAK_DIWAJIBKAN = 'TIDAK_DIWAJIBKAN';

    /**
     * Kode bawaan seeder yang dihitung HADIR pada rekapitulasi (§17).
     * Daftar pembanding cepat untuk laporan; sumber kebenaran tetap kolom
     * `is_present` agar tipe buatan sekolah sendiri ikut terpakai.
     */
    public const CODES_COUNTED_AS_PRESENT = [
        self::CODE_HADIR, self::CODE_TERLAMBAT,
    ];

    public const CATEGORY_PRESENT = 'present';

    public const CATEGORY_EXCUSED = 'excused';

    public const CATEGORY_UNEXCUSED = 'unexcused';

    public const CATEGORY_LATE = 'late';

    public const CATEGORY_NEUTRAL = 'neutral';

    public const CATEGORIES = [
        self::CATEGORY_PRESENT, self::CATEGORY_EXCUSED, self::CATEGORY_UNEXCUSED,
        self::CATEGORY_LATE, self::CATEGORY_NEUTRAL,
    ];

    public const CATEGORY_LABELS = [
        self::CATEGORY_PRESENT => 'Hadir',
        self::CATEGORY_EXCUSED => 'Ada Keterangan',
        self::CATEGORY_UNEXCUSED => 'Tanpa Keterangan',
        self::CATEGORY_LATE => 'Terlambat',
        self::CATEGORY_NEUTRAL => 'Netral',
    ];

    protected $casts = [
        'is_present' => 'boolean',
        'is_excused' => 'boolean',
        'requires_note' => 'boolean',
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(BoardingActivity::class, 'boarding_activity_attendance_types', 'attendance_type_id', 'activity_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(BoardingAttendance::class, 'attendance_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Tipe yang dihitung hadir pada rekap (§17). */
    public function scopePresent(Builder $query): Builder
    {
        return $query->where('is_present', true);
    }

    public function scopeExcused(Builder $query): Builder
    {
        return $query->where('is_excused', true);
    }

    public function scopeOfCategory(Builder $query, ?string $category): Builder
    {
        return ($category === null || $category === '') ? $query : $query->where('category', $category);
    }

    public function countsAsPresent(): bool
    {
        return (bool) $this->is_present;
    }

    public function requiresNote(): bool
    {
        return (bool) $this->requires_note;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? (string) $this->category;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_ATTENDANCE_TYPE';
    }
}
