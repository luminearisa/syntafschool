<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_attendance_windows`.
 */
class ReligiousAttendanceWindow extends Model
{
    protected $table = 'religious_attendance_windows';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'policy_id' => 'integer',
        'applies_days' => 'array',
        'active' => 'boolean',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PrayerAttendancePolicy::class, 'policy_id');
    }
}
