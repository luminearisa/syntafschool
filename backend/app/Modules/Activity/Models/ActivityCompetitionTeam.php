<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tim lomba beserta kapten, pelatih, dan anggotanya.
 *
 * Tabel: `activity_competition_teams`
 */
class ActivityCompetitionTeam extends ActivityModel
{
    protected $table = 'activity_competition_teams';

    protected $casts = [
        'status' => 'string',
    ];
    public function competition(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetition::class, 'competition_id');
    }
    public function category(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionCategory::class, 'category_id');
    }
    public function captain(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'captain_student_id');
    }
    public function coach(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'coach_employee_id');
    }
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(\App\Models\SchoolClass::class, 'school_class_id');
    }
    public function members(): HasMany
    {
        return $this->hasMany(ActivityCompetitionMember::class, 'team_id');
    }
    public function results(): HasMany
    {
        return $this->hasMany(ActivityCompetitionResult::class, 'team_id');
    }
}
