<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `prayer_attendance_policies`.
 */
class PrayerAttendancePolicy extends Model
{
    protected $table = 'prayer_attendance_policies';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'organization_id' => 'integer',
        'campus_id' => 'integer',
        'dormitory_id' => 'integer',
        'program_id' => 'integer',
        'prayer_types' => 'array',
        'grace_period_minutes' => 'integer',
        'late_threshold_minutes' => 'integer',
        'window_before_minutes' => 'integer',
        'window_after_minutes' => 'integer',
        'excused_rules' => 'array',
        'weekend_rules' => 'array',
        'holiday_rules' => 'array',
        'require_congregation' => 'boolean',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'priority' => 'integer',
        'active' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(RamadanProgram::class, 'program_id');
    }

    public function prayerAttendanceRecords(): HasMany
    {
        return $this->hasMany(PrayerAttendanceRecord::class, 'policy_id');
    }

    public function religiousAttendanceWindows(): HasMany
    {
        return $this->hasMany(ReligiousAttendanceWindow::class, 'policy_id');
    }

    public function religiousBoardingPrayerRules(): HasMany
    {
        return $this->hasMany(ReligiousBoardingPrayerRule::class, 'policy_id');
    }
}
