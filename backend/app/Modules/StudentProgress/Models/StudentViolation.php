<?php

namespace App\Modules\StudentProgress\Models;

use App\Models\Employee;
use App\Models\Student;
use App\Models\ViolationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pelanggaran siswa (tabel `student_violations`).
 *
 * `point` adalah SALINAN bobot `violation_types.point` pada saat kejadian —
 * mengubah bobot master setelahnya tidak mengoreksi total poin historis siswa.
 *
 * Status mengikuti alur `recorded` → `verified` → `resolved`; perpindahannya
 * hanya lewat endpoint khusus (verify/resolve), bukan lewat update bebas.
 */
class StudentViolation extends ProgressModel
{
    use SoftDeletes;

    protected $table = 'student_violations';

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_RESOLVED = 'resolved';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_RECORDED, self::STATUS_VERIFIED, self::STATUS_RESOLVED];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_RECORDED => 'Tercatat',
        self::STATUS_VERIFIED => 'Terverifikasi',
        self::STATUS_RESOLVED => 'Selesai',
    ];

    protected $casts = [
        'date' => 'date',
        'point' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function violationType(): BelongsTo
    {
        return $this->belongsTo(ViolationType::class);
    }

    /**
     * Pegawai yang melaporkan/mencatat pelanggaran.
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reported_by');
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    public function scopeOfType(Builder $query, ?int $typeId): Builder
    {
        return $typeId === null ? $query : $query->where('violation_type_id', $typeId);
    }

    public function scopeWithStatus(Builder $query, string|array|null $status): Builder
    {
        if ($status === null || $status === '') {
            return $query;
        }

        return $query->whereIn('status', (array) $status);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('student_violations.date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('student_violations.date', '<=', $to));
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function isRecorded(): bool
    {
        return $this->status === self::STATUS_RECORDED;
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }
}
