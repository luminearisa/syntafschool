<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `prayer_attendance_records`.
 */
class PrayerAttendanceRecord extends Model
{
    protected $table = 'prayer_attendance_records';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'campus_id' => 'integer',
        'prayer_schedule_id' => 'integer',
        'congregational_prayer_id' => 'integer',
        'attendance_date' => 'date',
        'student_id' => 'integer',
        'employee_id' => 'integer',
        'organization_id' => 'integer',
        'dormitory_id' => 'integer',
        'class_id' => 'integer',
        'recorded_by' => 'integer',
        'recorded_at' => 'datetime',
        'minutes_late' => 'integer',
        'policy_id' => 'integer',
        'is_adjusted' => 'boolean',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function prayerSchedule(): BelongsTo
    {
        return $this->belongsTo(PrayerSchedule::class, 'prayer_schedule_id');
    }

    public function congregationalPrayer(): BelongsTo
    {
        return $this->belongsTo(CongregationalPrayer::class, 'congregational_prayer_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PrayerAttendancePolicy::class, 'policy_id');
    }

    public function prayerAttendanceAdjustments(): HasMany
    {
        return $this->hasMany(PrayerAttendanceAdjustment::class, 'attendance_id');
    }
}
