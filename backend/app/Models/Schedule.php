<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jadwal pelajaran pokok (berlaku sepanjang semester).
 *
 * Perubahan harian — guru pengganti, pindah jam, pembatalan — tidak mengubah
 * baris ini, melainkan dicatat pada `schedule_changes`.
 */
class Schedule extends BaseModel
{
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(Day::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function timeSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ScheduleChange::class);
    }

    /** Batasi ke satu semester — jadwal tidak pernah lintas semester. */
    public function scopeForSemester($query, int $semesterId)
    {
        return $query->where('semester_id', $semesterId);
    }

    /** Jadwal yang jamnya beririsan dengan rentang [start, end) pada hari tertentu. */
    public function scopeOverlapping($query, int $dayId, string $start, string $end)
    {
        return $query->where('day_id', $dayId)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start);
    }
}
