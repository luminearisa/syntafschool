<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Event kalender akademik pada sebuah semester.
 *
 * Rentang tanggal inklusif: sebuah event menempati `start_date` sampai
 * `end_date`. Event bertanda `is_holiday` meniadakan hari sekolah pada rentang
 * itu (dipakai oleh generator hari efektif).
 */
class AcademicEvent extends BaseModel
{
    use SoftDeletes;

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'is_holiday' => 'boolean',
    ];

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    /**
     * Event yang rentangnya bersinggungan dengan rentang [start, end].
     */
    public function scopeOverlapping(Builder $query, string $start, string $end, ?int $ignoreId = null): Builder
    {
        return $query
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId));
    }

    /**
     * Event yang rentangnya berada di dalam rentang [from, to].
     */
    public function scopeInRange(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->whereDate('start_date', '>=', $from)
            ->whereDate('end_date', '<=', $to);
    }

    public function scopeHoliday(Builder $query): Builder
    {
        return $query->where('is_holiday', true);
    }
}
