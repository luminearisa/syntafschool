<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tindakan pendisiplinan (§37, §38): teguran lisan, pembinaan, pemberitahuan
 * orang tua, surat peringatan, atau kerja sosial.
 *
 * `violation_id` nullable dengan sengaja: tindakan bisa lahir dari akumulasi
 * poin (mis. ambang `point_threshold_parent_notification` pada settings) dan
 * bukan dari satu kejadian tunggal, jadi tidak boleh dipaksa menunjuk satu baris
 * pelanggaran.
 */
class BoardingDisciplinaryAction extends BaseModel
{
    use LogsBoardingActivity;

    public const ACTION_WARNING = 'warning';

    public const ACTION_COACHING = 'coaching';

    public const ACTION_PARENT_NOTIFICATION = 'parent_notification';

    public const ACTION_WRITTEN_WARNING = 'written_warning';

    public const ACTION_COMMUNITY_SERVICE = 'community_service';

    public const ACTION_OTHER = 'other';

    public const ACTIONS = [
        self::ACTION_WARNING, self::ACTION_COACHING, self::ACTION_PARENT_NOTIFICATION,
        self::ACTION_WRITTEN_WARNING, self::ACTION_COMMUNITY_SERVICE, self::ACTION_OTHER,
    ];

    public const ACTION_LABELS = [
        self::ACTION_WARNING => 'Teguran',
        self::ACTION_COACHING => 'Pembinaan',
        self::ACTION_PARENT_NOTIFICATION => 'Pemberitahuan Orang Tua',
        self::ACTION_WRITTEN_WARNING => 'Surat Peringatan',
        self::ACTION_COMMUNITY_SERVICE => 'Kerja Sosial',
        self::ACTION_OTHER => 'Lainnya',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu',
        self::STATUS_COMPLETED => 'Selesai',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function violation(): BelongsTo
    {
        return $this->belongsTo(BoardingViolation::class, 'violation_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('boarding_disciplinary_actions.date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('boarding_disciplinary_actions.date', '<=', $to));
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_DISCIPLINARY_ACTION';
    }
}
