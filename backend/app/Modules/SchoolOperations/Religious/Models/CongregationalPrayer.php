<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `congregational_prayers`.
 */
class CongregationalPrayer extends Model
{
    protected $table = 'congregational_prayers';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'prayer_schedule_id' => 'integer',
        'mosque_id' => 'integer',
        'congregation_date' => 'date',
        'imam_id' => 'integer',
        'muadzin_id' => 'integer',
        'substitute_imam_id' => 'integer',
        'substitute_muadzin_id' => 'integer',
        'participant_estimate' => 'integer',
        'participant_actual' => 'integer',
        'roster_id' => 'integer',
        'meta' => 'array',
    ];

    public function prayerSchedule(): BelongsTo
    {
        return $this->belongsTo(PrayerSchedule::class, 'prayer_schedule_id');
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function imam(): BelongsTo
    {
        return $this->belongsTo(ImamProfile::class, 'imam_id');
    }

    public function muadzin(): BelongsTo
    {
        return $this->belongsTo(MuadzinProfile::class, 'muadzin_id');
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(ReligiousDutyRoster::class, 'roster_id');
    }

    public function prayerAttendanceRecords(): HasMany
    {
        return $this->hasMany(PrayerAttendanceRecord::class, 'congregational_prayer_id');
    }
}
