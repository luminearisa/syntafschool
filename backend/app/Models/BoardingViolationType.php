<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master jenis pelanggaran asrama (§35, §36).
 *
 * Poin disimpan di sini, BUKAN di catatan pelanggaran, supaya kebijakan poin
 * bisa direvisi tanpa mengubah histori: setiap violation menyalin nilainya
 * sebagai snapshot pada saat kejadian.
 *
 * Master berbeda dari `violation_types` modul kesiswaan (milik sekolah harian):
 * pelanggaran asrama punya bobot dan kategori sendiri (ibadah, tahfidz,
 * kebersihan kamar).
 */
class BoardingViolationType extends BaseModel
{
    use LogsBoardingActivity;

    public const CATEGORY_IBADAH = 'ibadah';

    public const CATEGORY_ACADEMIC = 'academic';

    public const CATEGORY_TAHFIDZ = 'tahfidz';

    public const CATEGORY_CLEANLINESS = 'cleanliness';

    public const CATEGORY_DISCIPLINE = 'discipline';

    public const CATEGORY_SOCIAL = 'social';

    public const CATEGORY_HEALTH = 'health';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_IBADAH, self::CATEGORY_ACADEMIC, self::CATEGORY_TAHFIDZ,
        self::CATEGORY_CLEANLINESS, self::CATEGORY_DISCIPLINE, self::CATEGORY_SOCIAL,
        self::CATEGORY_HEALTH, self::CATEGORY_OTHER,
    ];

    public const CATEGORY_LABELS = [
        self::CATEGORY_IBADAH => 'Ibadah',
        self::CATEGORY_ACADEMIC => 'Akademik',
        self::CATEGORY_TAHFIDZ => 'Tahfidz',
        self::CATEGORY_CLEANLINESS => 'Kebersihan',
        self::CATEGORY_DISCIPLINE => 'Kedisiplinan',
        self::CATEGORY_SOCIAL => 'Sosial',
        self::CATEGORY_HEALTH => 'Kesehatan',
        self::CATEGORY_OTHER => 'Lainnya',
    ];

    public const SEVERITY_MINOR = 'minor';

    public const SEVERITY_MODERATE = 'moderate';

    public const SEVERITY_SEVERE = 'severe';

    public const SEVERITIES = [self::SEVERITY_MINOR, self::SEVERITY_MODERATE, self::SEVERITY_SEVERE];

    public const SEVERITY_LABELS = [
        self::SEVERITY_MINOR => 'Ringan',
        self::SEVERITY_MODERATE => 'Sedang',
        self::SEVERITY_SEVERE => 'Berat',
    ];

    protected $casts = [
        'point' => 'integer',
        'is_active' => 'boolean',
    ];

    public function violations(): HasMany
    {
        return $this->hasMany(BoardingViolation::class, 'violation_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfCategory(Builder $query, ?string $category): Builder
    {
        return ($category === null || $category === '') ? $query : $query->where('category', $category);
    }

    public function scopeOfSeverity(Builder $query, ?string $severity): Builder
    {
        return ($severity === null || $severity === '') ? $query : $query->where('severity', $severity);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function severityLabel(): string
    {
        return self::SEVERITY_LABELS[$this->severity] ?? $this->severity;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_VIOLATION_TYPE';
    }
}
