<?php

namespace App\Modules\Mutation\Models;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Permohonan mutasi siswa.
 *
 * Siklus status: draft → submitted → approved/rejected → completed.
 * Efek terhadap data siswa (keaktifan & keanggotaan rombel) baru diterapkan
 * pada transisi ke `completed` lewat StudentTransferService.
 */
class StudentTransfer extends MutationModel
{
    use SoftDeletes;

    public const TYPE_MASUK = 'masuk';

    public const TYPE_KELUAR = 'keluar';

    public const TYPE_PINDAH_KELAS = 'pindah_kelas';

    public const TYPE_PINDAH_SEKOLAH = 'pindah_sekolah';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_COMPLETED = 'completed';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_MASUK,
        self::TYPE_KELUAR,
        self::TYPE_PINDAH_KELAS,
        self::TYPE_PINDAH_SEKOLAH,
    ];

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
    ];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_MASUK => 'Masuk',
        self::TYPE_KELUAR => 'Keluar',
        self::TYPE_PINDAH_KELAS => 'Pindah Kelas',
        self::TYPE_PINDAH_SEKOLAH => 'Pindah Sekolah',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draf',
        self::STATUS_SUBMITTED => 'Diajukan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_COMPLETED => 'Selesai',
    ];

    protected $casts = [
        'request_date' => 'date',
        'effective_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function fromClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'from_class_id');
    }

    public function toClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'to_class_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(StudentTransferHistory::class, 'student_transfer_id')
            ->orderBy('id');
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
