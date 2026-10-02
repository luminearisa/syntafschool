<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `prayer_time_sources`.
 */
class PrayerTimeSource extends Model
{
    protected $table = 'prayer_time_sources';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'config_data' => 'array',
        'priority' => 'integer',
        'active' => 'boolean',
    ];

    public function prayerSchedules(): HasMany
    {
        return $this->hasMany(PrayerSchedule::class, 'source_id');
    }
}
