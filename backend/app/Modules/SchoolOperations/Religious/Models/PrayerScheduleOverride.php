<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `prayer_schedule_overrides`.
 */
class PrayerScheduleOverride extends Model
{
    protected $table = 'prayer_schedule_overrides';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'prayer_schedule_id' => 'integer',
        'campus_id' => 'integer',
        'mosque_id' => 'integer',
        'override_date' => 'date',
        'approved_by' => 'integer',
    ];

    public function prayerSchedule(): BelongsTo
    {
        return $this->belongsTo(PrayerSchedule::class, 'prayer_schedule_id');
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }
}
