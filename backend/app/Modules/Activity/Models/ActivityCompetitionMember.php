<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Anggota tim — penentu siapa yang berhak menerima prestasi tim.
 *
 * Tabel: `activity_competition_members`
 */
class ActivityCompetitionMember extends ActivityModel
{
    protected $table = 'activity_competition_members';

    protected $casts = [
        'receives_achievement' => 'boolean',
    ];
    public function team(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionTeam::class, 'team_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
}
