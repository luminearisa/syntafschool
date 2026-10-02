<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `prayer_schedules`.
 */
class PrayerSchedule extends Model
{
    protected $table = 'prayer_schedules';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'schedule_date' => 'date',
        'campus_id' => 'integer',
        'mosque_id' => 'integer',
        'source_id' => 'integer',
        'is_ramadan' => 'boolean',
        'version_number' => 'integer',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function sourceRef(): BelongsTo
    {
        return $this->belongsTo(PrayerTimeSource::class, 'source_id');
    }

    public function prayerScheduleVersions(): HasMany
    {
        return $this->hasMany(PrayerScheduleVersion::class, 'schedule_id');
    }

    public function prayerScheduleOverrides(): HasMany
    {
        return $this->hasMany(PrayerScheduleOverride::class, 'prayer_schedule_id');
    }

    public function congregationalPrayers(): HasMany
    {
        return $this->hasMany(CongregationalPrayer::class, 'prayer_schedule_id');
    }

    public function imamAssignments(): HasMany
    {
        return $this->hasMany(ImamAssignment::class, 'prayer_schedule_id');
    }

    public function prayerAttendanceRecords(): HasMany
    {
        return $this->hasMany(PrayerAttendanceRecord::class, 'prayer_schedule_id');
    }
}
