<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Intervention untuk peserta yang tertinggal dari target.
 *
 * Alur: target gagal → sistem mendeteksi → peringatan → guru/musyrif meninjau
 * → intervention (tambahan murojaah, pengurangan target, sesi khusus,
 * bimbingan individual, remedial hafalan, naik/turun level) → pemantauan.
 */
class TahfizIntervention extends BaseModel
{
    use SoftDeletes;

    public const TYPE_TAMBAHAN_MUROJAAH = 'tambahan_murojaah';
    public const TYPE_PENGURANGAN_TARGET = 'pengurangan_target';
    public const TYPE_SESI_KHUSUS = 'sesi_khusus';
    public const TYPE_BIMBINGAN_INDIVIDUAL = 'bimbingan_individual';
    public const TYPE_REMEDIAL_HAFALAN = 'remedial_hafalan';
    public const TYPE_NAIK_LEVEL = 'naik_level';
    public const TYPE_TURUN_LEVEL = 'turun_level';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_TAMBAHAN_MUROJAAH, self::TYPE_PENGURANGAN_TARGET,
        self::TYPE_SESI_KHUSUS, self::TYPE_BIMBINGAN_INDIVIDUAL,
        self::TYPE_REMEDIAL_HAFALAN, self::TYPE_NAIK_LEVEL, self::TYPE_TURUN_LEVEL,
    ];

    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PROPOSED, self::STATUS_IN_PROGRESS, self::STATUS_DONE, self::STATUS_CANCELLED,
    ];

    /** Status yang masih perlu tindak lanjut. */
    public const STATUSES_OPEN = [
        self::STATUS_PROPOSED, self::STATUS_IN_PROGRESS,
    ];

    /** Perpindahan status yang diizinkan, dari status kunci. */
    public const TRANSITIONS = [
        self::STATUS_PROPOSED => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_DONE, self::STATUS_CANCELLED],
        self::STATUS_DONE => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'completed_at' => 'date:Y-m-d',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PROPOSED, self::STATUS_IN_PROGRESS]);
    }

    /** Sudah final — tidak boleh diubah lagi. */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_CANCELLED], true);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TahfizEnrollment::class, 'enrollment_id');
    }

    public function halaqah(): BelongsTo
    {
        return $this->belongsTo(TahfizHalaqah::class, 'halaqah_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
