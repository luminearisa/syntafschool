<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pendaftaran satu peserta pada satu program Tahfiz.
 *
 * Ini adalah inti pengelolaan peserta: level, halaqah, dan konteks akademik
 * (untuk siswa) semuanya bergantung ke sini. Aktivitas (setoran, murojaah,
 * …) merujuk ke enrollment agar selalu jelas peserta terdaftar di program mana.
 *
 * Alur status:
 * draft → registered → assessment → approved → active → (completed | suspended | withdrawn)
 *                         ↑_________________________________________|  (rejected kapan saja sebelum active)
 */
class TahfizEnrollment extends BaseModel
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_ASSESSMENT = 'assessment';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_REGISTERED, self::STATUS_ASSESSMENT,
        self::STATUS_APPROVED, self::STATUS_ACTIVE, self::STATUS_SUSPENDED,
        self::STATUS_COMPLETED, self::STATUS_WITHDRAWN, self::STATUS_REJECTED,
    ];

    /** Status yang dianggap masih dalam program (bukan selesai/keluar). */
    public const ONGOING_STATUSES = [
        self::STATUS_REGISTERED, self::STATUS_ASSESSMENT, self::STATUS_APPROVED, self::STATUS_ACTIVE,
    ];

    /** Status yang berarti peserta sudah benar-benar keluar dari program. */
    public const FINAL_STATUSES = [
        self::STATUS_COMPLETED, self::STATUS_WITHDRAWN, self::STATUS_REJECTED,
    ];

    protected $casts = [
        'enrolled_at' => 'date:Y-m-d',
        'start_date' => 'date:Y-m-d',
        'expected_end_date' => 'date:Y-m-d',
        'completed_at' => 'date:Y-m-d',
        'approved_at' => 'datetime',
    ];

    public function scopeOngoing(Builder $query): Builder
    {
        return $query->whereIn('status', self::ONGOING_STATUSES);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(TahfizProgram::class, 'program_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(TahfizLevel::class, 'level_id');
    }

    public function halaqah(): BelongsTo
    {
        return $this->belongsTo(TahfizHalaqah::class, 'halaqah_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function individualTargets()
    {
        return $this->hasMany(TahfizIndividualTarget::class, 'enrollment_id');
    }

    public function memorizationRecords()
    {
        return $this->hasMany(TahfizMemorizationRecord::class, 'enrollment_id');
    }

    public function certificates()
    {
        return $this->hasMany(TahfizCertificate::class, 'enrollment_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Generator kode enrollment — satu-satunya tempat agar tidak ada dua
     * pembuat kode yang saling bertabrakan pada kolom unik `code`.
     */
    public static function nextCode(): string
    {
        $latest = (int) (self::query()->max('id') ?? 0);

        return 'THZ-ENR-'.str_pad((string) ($latest + 1), 6, '0', STR_PAD_LEFT);
    }
}
