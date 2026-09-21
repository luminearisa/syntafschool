<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Peserta Tahfiz — entitas tunggal untuk semua jenis peserta.
 *
 * Tidak ada tabel terpisah untuk siswa/pegawai/wali/umum. `participant_type`
 * membedakannya, dan tepat satu kolom tautan (`student_id` / `employee_id` /
 * `guardian_id`) yang terisi. Peserta umum dan alumni tidak punya entitas
 * induk, sehingga identitasnya disimpan langsung di sini.
 *
 * Satu user dapat memegang lebih dari satu konteks: seorang wali yang juga
 * mengikuti program Tahfiz Dewasa tetap satu akun, menjadi peserta jenis
 * `guardian`.
 */
class TahfizParticipant extends BaseModel
{
    use SoftDeletes;

    public const TYPE_STUDENT = 'student';
    public const TYPE_EMPLOYEE = 'employee';
    public const TYPE_GUARDIAN = 'guardian';
    public const TYPE_PUBLIC = 'public';
    public const TYPE_ALUMNI = 'alumni';
    public const TYPE_OTHER = 'other';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_STUDENT, self::TYPE_EMPLOYEE, self::TYPE_GUARDIAN,
        self::TYPE_PUBLIC, self::TYPE_ALUMNI, self::TYPE_OTHER,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_BLACKLISTED = 'blacklisted';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_BLACKLISTED];

    /** Jenis peserta yang tidak punya entitas induk. */
    public const SELF_MANAGED_TYPES = [self::TYPE_PUBLIC, self::TYPE_ALUMNI, self::TYPE_OTHER];

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('participant_type', $type);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(TahfizParticipantProfile::class, 'participant_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(TahfizEnrollment::class, 'participant_id');
    }

    public function activeEnrollments(): HasMany
    {
        return $this->enrollments()->whereIn('status', [
            TahfizEnrollment::STATUS_ACTIVE, TahfizEnrollment::STATUS_APPROVED,
        ]);
    }

    public function memorizationRecords(): HasMany
    {
        return $this->hasMany(TahfizMemorizationRecord::class, 'participant_id');
    }

    public function revisionRecords(): HasMany
    {
        return $this->hasMany(TahfizRevisionRecord::class, 'participant_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(TahfizParticipantProgress::class, 'participant_id');
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(TahfizIntervention::class, 'participant_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(TahfizNotification::class, 'participant_id');
    }

    /**
     * Nama entitas induk bila peserta terikat ke siswa/pegawai/wali.
     */
    public function resolveName(): ?string
    {
        return match ($this->participant_type) {
            self::TYPE_STUDENT => $this->student?->full_name,
            self::TYPE_EMPLOYEE => $this->employee?->full_name,
            self::TYPE_GUARDIAN => $this->guardian?->full_name,
            default => null,
        };
    }
}
