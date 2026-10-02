<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `imam_assignments`.
 */
class ImamAssignment extends Model
{
    protected $table = 'imam_assignments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'imam_id' => 'integer',
        'mosque_id' => 'integer',
        'roster_id' => 'integer',
        'prayer_schedule_id' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'assigned_by' => 'integer',
    ];

    public function imam(): BelongsTo
    {
        return $this->belongsTo(ImamProfile::class, 'imam_id');
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(ReligiousDutyRoster::class, 'roster_id');
    }

    public function prayerSchedule(): BelongsTo
    {
        return $this->belongsTo(PrayerSchedule::class, 'prayer_schedule_id');
    }
}
