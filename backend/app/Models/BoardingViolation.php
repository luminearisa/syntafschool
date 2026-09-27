<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catatan pelanggaran santri di asrama (§36).
 *
 * `points` adalah SALINAN poin master pada saat kejadian — kalau nanti jenis
 * pelanggaran diubah bobotnya, total poin historis santri tidak ikut berubah.
 * Status `open`/`actioned`/`closed` menunjukkan apakah sudah ditindaklanjuti
 * dengan tindakan pendisiplinan.
 */
class BoardingViolation extends BaseModel
{
    use LogsBoardingActivity;

    public const STATUS_OPEN = 'open';

    public const STATUS_ACTIONED = 'actioned';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_ACTIONED, self::STATUS_CLOSED];

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Belum Ditindaklanjuti',
        self::STATUS_ACTIONED => 'Sudah Ditindaklanjuti',
        self::STATUS_CLOSED => 'Ditutup',
    ];

    protected $casts = [
        'date' => 'date',
        'points' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(BoardingViolationType::class, 'violation_type_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function disciplinaryActions(): HasMany
    {
        return $this->hasMany(BoardingDisciplinaryAction::class, 'violation_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('boarding_violations.date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('boarding_violations.date', '<=', $to));
    }

    public function scopeOfType(Builder $query, ?int $typeId): Builder
    {
        return $typeId === null ? $query : $query->where('violation_type_id', $typeId);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_VIOLATION';
    }
}
