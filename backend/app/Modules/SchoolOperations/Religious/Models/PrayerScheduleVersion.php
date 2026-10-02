<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `prayer_schedule_versions`.
 */
class PrayerScheduleVersion extends Model
{
    protected $table = 'prayer_schedule_versions';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'schedule_id' => 'integer',
        'version_number' => 'integer',
        'snapshot' => 'array',
        'changed_by' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'created_by' => 'integer',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PrayerSchedule::class, 'schedule_id');
    }
}
