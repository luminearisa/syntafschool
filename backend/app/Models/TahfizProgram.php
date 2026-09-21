<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Program Tahfiz yang ditawarkan (Reguler, Intensif, Dewasa, 1 Juz, …).
 *
 * Program bersifat konfigurasi: jenis peserta yang diterima, durasi, target,
 * kehadiran minimum, dan skema penilaian ditentukan per program — bukan
 * ditulis keras di kode. `assessment_scheme` disimpan sebagai json agar setiap
 * program bebas menentukan komponen nilainya.
 */
class TahfizProgram extends BaseModel
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ARCHIVED];

    public const PARTICIPANT_STUDENT = 'student';
    public const PARTICIPANT_EMPLOYEE = 'employee';
    public const PARTICIPANT_GUARDIAN = 'guardian';
    public const PARTICIPANT_PUBLIC = 'public';
    public const PARTICIPANT_ALUMNI = 'alumni';
    public const PARTICIPANT_OTHER = 'other';

    /** @var list<string> */
    public const PARTICIPANT_TYPES = [
        self::PARTICIPANT_STUDENT, self::PARTICIPANT_EMPLOYEE, self::PARTICIPANT_GUARDIAN,
        self::PARTICIPANT_PUBLIC, self::PARTICIPANT_ALUMNI, self::PARTICIPANT_OTHER,
    ];

    public const TARGET_JUZ = 'juz';
    public const TARGET_SURAH = 'surah';
    public const TARGET_AYAT = 'ayat';
    public const TARGET_PAGE = 'page';
    public const TARGET_NONE = 'none';

    /** @var list<string> */
    public const TARGET_TYPES = [
        self::TARGET_JUZ, self::TARGET_SURAH, self::TARGET_AYAT, self::TARGET_PAGE, self::TARGET_NONE,
    ];

    protected $casts = [
        'duration_value' => 'integer',
        'minimum_attendance' => 'integer',
        'assessment_scheme' => 'array',
        'requires_placement_test' => 'boolean',
        'self_registration' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(TahfizLevel::class, 'program_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(TahfizEnrollment::class, 'program_id');
    }

    public function halaqahs(): HasMany
    {
        return $this->hasMany(TahfizHalaqah::class, 'program_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(TahfizTarget::class, 'program_id');
    }
}
