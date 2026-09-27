<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan kesehatan asrama (§39) — sederhana, BUKAN rekam medis.
 *
 * Baris berstatus `reported`/`being_treated` dianggap "masih sakit" oleh
 * layanan status (§42): santri dengan penempatan aktif di kamar bertipe
 * `isolation` dilaporkan `isolation`, sisanya `sick`. Status `recovered` dan
 * `referred` menutup catatan.
 */
class BoardingHealthRecord extends BaseModel
{
    use LogsBoardingActivity;

    public const STATUS_REPORTED = 'reported';

    public const STATUS_BEING_TREATED = 'being_treated';

    public const STATUS_RECOVERED = 'recovered';

    public const STATUS_REFERRED = 'referred';

    public const STATUSES = [
        self::STATUS_REPORTED, self::STATUS_BEING_TREATED,
        self::STATUS_RECOVERED, self::STATUS_REFERRED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_REPORTED => 'Tercatat',
        self::STATUS_BEING_TREATED => 'Dalam Perawatan',
        self::STATUS_RECOVERED => 'Pulih',
        self::STATUS_REFERRED => 'Dirujuk',
    ];

    /** Status yang masih berarti santri sedang sakit (§42). */
    public const OPEN_STATUSES = [self::STATUS_REPORTED, self::STATUS_BEING_TREATED];

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

    /** Catatan yang belum selesai. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('boarding_health_records.date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('boarding_health_records.date', '<=', $to));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_HEALTH_RECORD';
    }
}
