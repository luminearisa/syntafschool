<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_boarding_prayer_rules`.
 */
class ReligiousBoardingPrayerRule extends Model
{
    protected $table = 'religious_boarding_prayer_rules';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'dormitory_id' => 'integer',
        'program_id' => 'integer',
        'prayer_types' => 'array',
        'policy_id' => 'integer',
        'required' => 'boolean',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'meta' => 'array',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(RamadanProgram::class, 'program_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PrayerAttendancePolicy::class, 'policy_id');
    }
}
