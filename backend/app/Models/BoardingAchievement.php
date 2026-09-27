<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prestasi santri di asrama (§41): hafalan, lomba, kepemimpinan kamar.
 *
 * Sengaja terpisah dari modul korespondensi/akademik — asrama melaporkan
 * capaiannya sendiri (target hafalan, santri teladan) dan angkanya dipakai
 * kembali di laporan poin sebagai penyeimbang pelanggaran (§36).
 */
class BoardingAchievement extends BaseModel
{
    use LogsBoardingActivity;

    public const CATEGORY_TAHFIDZ = 'tahfidz';

    public const CATEGORY_ACADEMIC = 'academic';

    public const CATEGORY_SPORTS = 'sports';

    public const CATEGORY_LANGUAGE = 'language';

    public const CATEGORY_LEADERSHIP = 'leadership';

    public const CATEGORY_CLEANLINESS = 'cleanliness';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_TAHFIDZ, self::CATEGORY_ACADEMIC, self::CATEGORY_SPORTS,
        self::CATEGORY_LANGUAGE, self::CATEGORY_LEADERSHIP, self::CATEGORY_CLEANLINESS,
        self::CATEGORY_OTHER,
    ];

    public const CATEGORY_LABELS = [
        self::CATEGORY_TAHFIDZ => 'Tahfidz',
        self::CATEGORY_ACADEMIC => 'Akademik',
        self::CATEGORY_SPORTS => 'Olahraga',
        self::CATEGORY_LANGUAGE => 'Bahasa',
        self::CATEGORY_LEADERSHIP => 'Kepemimpinan',
        self::CATEGORY_CLEANLINESS => 'Kebersihan',
        self::CATEGORY_OTHER => 'Lainnya',
    ];

    /** Jenjang pengakuan — kolomnya string bebas, konstanta ini untuk dropdown. */
    public const LEVEL_SCHOOL = 'sekolah';

    public const LEVEL_CITY = 'kota';

    public const LEVEL_PROVINCE = 'provinsi';

    public const LEVEL_NATIONAL = 'nasional';

    public const LEVELS = [
        self::LEVEL_SCHOOL, self::LEVEL_CITY, self::LEVEL_PROVINCE, self::LEVEL_NATIONAL,
    ];

    public const LEVEL_LABELS = [
        self::LEVEL_SCHOOL => 'Tingkat Sekolah',
        self::LEVEL_CITY => 'Tingkat Kota/Kabupaten',
        self::LEVEL_PROVINCE => 'Tingkat Provinsi',
        self::LEVEL_NATIONAL => 'Tingkat Nasional',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    public function scopeOfCategory(Builder $query, ?string $category): Builder
    {
        return ($category === null || $category === '') ? $query : $query->where('category', $category);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('boarding_achievements.date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('boarding_achievements.date', '<=', $to));
    }

    public function levelLabel(): string
    {
        if ($this->level === null || $this->level === '') {
            return '-';
        }

        return self::LEVEL_LABELS[$this->level] ?? $this->level;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_ACHIEVEMENT';
    }
}
