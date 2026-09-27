<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kepulangan santri ke rumah (§30) — dipisah dari izin keluar biasa karena
 * dimensinya hari, bukan jam, dan penjemputnya dicatat (siapa yang membawa
 * pulang, siapa yang mengantar kembali).
 *
 * Alur status sama dengan izin keluar (draft → submitted → approved → out →
 * returned) sehingga petugas membaca satu daftar antrean, tetapi layanannya
 * berbeda: kepulangan terjadwal (akhir pekan/semester/Lebaran) dibuat massal.
 */
class BoardingHomecoming extends BaseModel
{
    use LogsBoardingActivity;

    public const TYPE_WEEKEND = 'weekend';

    public const TYPE_SEMESTER_BREAK = 'semester_break';

    public const TYPE_LEBARAN = 'lebaran';

    public const TYPE_SPECIAL = 'special';

    public const TYPES = [
        self::TYPE_WEEKEND, self::TYPE_SEMESTER_BREAK, self::TYPE_LEBARAN, self::TYPE_SPECIAL,
    ];

    public const TYPE_LABELS = [
        self::TYPE_WEEKEND => 'Akhir pekan',
        self::TYPE_SEMESTER_BREAK => 'Libur semester',
        self::TYPE_LEBARAN => 'Libur Lebaran',
        self::TYPE_SPECIAL => 'Kepulangan khusus',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_OUT = 'out';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED,
        self::STATUS_REJECTED, self::STATUS_OUT, self::STATUS_RETURNED,
        self::STATUS_EXPIRED, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SUBMITTED => 'Menunggu Persetujuan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_OUT => 'Pulang',
        self::STATUS_RETURNED => 'Sudah Kembali',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    /** Masih berada di rumah (sudah di-check out, belum kembali) (§42). */
    public const OUT_STATUSES = [self::STATUS_APPROVED, self::STATUS_OUT];

    public const OPEN_STATUSES = [
        self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_OUT,
    ];

    protected $casts = [
        'home_date' => 'date',
        'return_date' => 'date',
        'actual_return_date' => 'date',
        'late_return' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    /** Santri yang seharusnya sudah kembali tapi belum masuk asrama (§31). */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OUT)
            ->where('return_date', '<', now()->toDateString());
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return ($type === null || $type === '') ? $query : $query->where('type', $type);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isHome(): bool
    {
        return $this->status === self::STATUS_OUT;
    }

    public function isLateReturn(): bool
    {
        return (bool) $this->late_return
            || ($this->actual_return_date !== null && $this->return_date !== null
                && $this->actual_return_date->greaterThan($this->return_date));
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_HOMECOMING';
    }
}
