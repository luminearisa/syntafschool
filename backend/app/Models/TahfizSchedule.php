<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Jadwal berulang per jenis aktivitas Tahfiz (setoran, murojaah, tahsin,
 * tilawah, ujian, mentoring, halaqah) berdasarkan hari dalam pekan.
 */
class TahfizSchedule extends BaseModel
{
    use SoftDeletes;

    public const ACTIVITY_SETORAN = 'setoran';
    public const ACTIVITY_MUROJAAH = 'murojaah';
    public const ACTIVITY_TAHSIN = 'tahsin';
    public const ACTIVITY_TILAWAH = 'tilawah';
    public const ACTIVITY_UJIAN = 'ujian';
    public const ACTIVITY_MENTORING = 'mentoring';
    public const ACTIVITY_HALAQAH = 'halaqah';

    /** @var list<string> */
    public const ACTIVITY_TYPES = [
        self::ACTIVITY_SETORAN, self::ACTIVITY_MUROJAAH, self::ACTIVITY_TAHSIN,
        self::ACTIVITY_TILAWAH, self::ACTIVITY_UJIAN, self::ACTIVITY_MENTORING,
        self::ACTIVITY_HALAQAH,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const DAY_LABELS = [
        0 => 'Ahad', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu',
        4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Jadwal yang berlaku pada tanggal tertentu. */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
            })
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
            });
    }

    public function halaqah(): BelongsTo
    {
        return $this->belongsTo(TahfizHalaqah::class, 'halaqah_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(TahfizProgram::class, 'program_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'instructor_id');
    }

    public function sessions()
    {
        return $this->hasMany(TahfizSession::class, 'schedule_id');
    }

    public function dayLabel(): string
    {
        return self::DAY_LABELS[$this->day_of_week] ?? (string) $this->day_of_week;
    }
}
