<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Periode aktivitas: tahun ajaran, semester, term, atau musim kompetisi.
 *
 * Tabel: `activity_periods`
 */
class ActivityPeriod extends ActivityModel
{
    protected $table = 'activity_periods';

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'registration_opens' => 'date',
        'registration_closes' => 'date',
        'is_current' => 'boolean',
    ];
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(\App\Models\AcademicYear::class, 'academic_year_id');
    }
    public function semester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Semester::class, 'semester_id');
    }
    public function programs(): HasMany
    {
        return $this->hasMany(ActivityProgram::class, 'period_id');
    }
    public function memberships(): HasMany
    {
        return $this->hasMany(ActivityMembership::class, 'period_id');
    }
}
