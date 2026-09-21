<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Presensi siswa per tanggal (opsional terikat jadwal / jam pelajaran).
 *
 * Menjawab: "hari ini siswa ini hadir, sakit, izin, alpa, atau terlambat —
 * dan berapa persen kehadirannya sepanjang semester?"
 */
class StudentAttendance extends BaseModel
{
    /** Baru dicatat guru, belum diverifikasi. */
    public const STATUS_RECORDED = 'recorded';

    /** Sudah diverifikasi wali kelas / admin. */
    public const STATUS_VERIFIED = 'verified';

    /** Terkunci — tidak boleh diubah tanpa koreksi resmi. */
    public const STATUS_LOCKED = 'locked';

    /** Seluruh status yang sah — dipakai aturan validasi `Rule::in()`. */
    public const STATUSES = [
        self::STATUS_RECORDED,
        self::STATUS_VERIFIED,
        self::STATUS_LOCKED,
    ];

    protected $casts = [
        'date' => 'date',
        'verified_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function attendanceStatus(): BelongsTo
    {
        return $this->belongsTo(AttendanceStatus::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function scopeOnDate($query, string $date)
    {
        return $query->whereDate('date', $date);
    }

    public function scopeForSemester($query, int $semesterId)
    {
        return $query->where('semester_id', $semesterId);
    }

    public function scopeBetween($query, string $from, string $to)
    {
        return $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
    }

    public function scopeWithStatusId($query, int $statusId)
    {
        return $query->where('attendance_status_id', $statusId);
    }

    public function scopeForClass($query, int $classId)
    {
        return $query->where('school_class_id', $classId);
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }
}
