<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Jurnal mengajar harian — catatan realisasi satu jam pelajaran.
 */
class TeachingJournal extends BaseModel
{
    use SoftDeletes;

    public const STATUS_PLANNED = 'planned';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Seluruh status yang sah — dipakai aturan validasi `Rule::in()`. */
    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_ONGOING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $casts = [
        'date' => 'date',
        'student_present_count' => 'integer',
        'realized_jp' => 'integer',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'teacher_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function timeSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** Jurnal yang benar-benar terlaksana — dipakai menghitung realisasi JP. */
    public function scopeRealized($query)
    {
        return $query->where('status', '!=', self::STATUS_CANCELLED);
    }
}
