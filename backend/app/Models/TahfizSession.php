<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sesi konkret: satu pertemuan pada satu tanggal.
 *
 * Kehadiran, setoran, dan murojaah bergantung ke sesi (atau ke halaqah bila
 * sesi tidak dibuat), bukan ke jadwal berulang.
 */
class TahfizSession extends BaseModel
{
    use SoftDeletes;

    public const STATUS_PLANNED = 'planned';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PLANNED, self::STATUS_ONGOING, self::STATUS_DONE, self::STATUS_CANCELLED,
    ];

    protected $casts = [
        'date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
    ];

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('date', $date);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereDate('date', '>=', now()->toDateString())->orderBy('date');
    }

    public function halaqah(): BelongsTo
    {
        return $this->belongsTo(TahfizHalaqah::class, 'halaqah_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TahfizSchedule::class, 'schedule_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'instructor_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(TahfizAttendance::class, 'session_id');
    }

    public function memorizationRecords(): HasMany
    {
        return $this->hasMany(TahfizMemorizationRecord::class, 'session_id');
    }
}
