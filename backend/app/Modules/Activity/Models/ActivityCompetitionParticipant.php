<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\ParticipantStatus;

/**
 * Peserta kompetisi (individu) — dapat juga tergabung dalam tim.
 *
 * Tabel: `activity_competition_participants`
 */
class ActivityCompetitionParticipant extends ActivityModel
{
    protected $table = 'activity_competition_participants';

    protected $casts = [
        'status' => ParticipantStatus::class,
        'registered_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
    public function competition(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetition::class, 'competition_id');
    }
    public function category(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionCategory::class, 'category_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function team(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionTeam::class, 'team_id');
    }
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'verified_by');
    }
    public function scores(): HasMany
    {
        return $this->hasMany(ActivityCompetitionScore::class, 'participant_id');
    }
    public function results(): HasMany
    {
        return $this->hasMany(ActivityCompetitionResult::class, 'participant_id');
    }
}
