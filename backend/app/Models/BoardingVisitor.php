<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kunjungan ke asrama (§40): tamu menunggu, sedang berkunjung, selesai, batal.
 *
 * Identitas tamu disimpan sebagai teks (`visitor_name`, `identity_number`) dan
 * tidak dibuat sebagai master baru — kunjungan adalah transaksi, bukan data
 * induk. `check_in_at`/`check_out_at` membedakan "datang" dan "dipersilakan
 * pulang", sehingga daftar `waiting` yang terlupakan terlihat di dashboard.
 */
class BoardingVisitor extends BaseModel
{
    use LogsBoardingActivity;

    public const STATUS_WAITING = 'waiting';

    public const STATUS_VISITING = 'visiting';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_WAITING, self::STATUS_VISITING,
        self::STATUS_FINISHED, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_WAITING => 'Menunggu',
        self::STATUS_VISITING => 'Sedang Berkunjung',
        self::STATUS_FINISHED => 'Selesai',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    /** Tamu yang masih berada di area asrama. */
    public const PRESENT_STATUSES = [self::STATUS_WAITING, self::STATUS_VISITING];

    protected $casts = [
        'visit_date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeOnDate(Builder $query, ?string $date): Builder
    {
        return ($date === null || $date === '') ? $query : $query->whereDate('visit_date', $date);
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    /** Masih di dalam area asrama. */
    public function scopePresent(Builder $query): Builder
    {
        return $query->whereIn('status', self::PRESENT_STATUSES);
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITING);
    }

    public function isPresent(): bool
    {
        return in_array($this->status, self::PRESENT_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_VISITOR';
    }
}
