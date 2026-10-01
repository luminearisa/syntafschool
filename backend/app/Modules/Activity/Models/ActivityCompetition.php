<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Activity\Domain\Enums\CompetitionLevel;
use App\Modules\Activity\Domain\Enums\CompetitionStatus;
use App\Modules\Activity\Domain\Enums\ParticipantType;

/**
 * Kompetisi — wadah lomba, babak, juri, skor, dan hasil.
 *
 * Tabel: `activity_competitions`
 */
class ActivityCompetition extends ActivityModel
{
    protected $table = 'activity_competitions';

    use SoftDeletes;

    protected $casts = [
        'level' => CompetitionLevel::class,
        'participant_type' => ParticipantType::class,
        'status' => CompetitionStatus::class,
        'registration_opens' => 'date',
        'registration_closes' => 'date',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'fee_amount' => 'decimal:2',
        'scores_locked' => 'boolean',
        'results_published' => 'boolean',
        'metadata' => 'array',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function categories(): HasMany
    {
        return $this->hasMany(ActivityCompetitionCategory::class, 'competition_id');
    }
    public function teams(): HasMany
    {
        return $this->hasMany(ActivityCompetitionTeam::class, 'competition_id');
    }
    public function participants(): HasMany
    {
        return $this->hasMany(ActivityCompetitionParticipant::class, 'competition_id');
    }
    public function rounds(): HasMany
    {
        return $this->hasMany(ActivityCompetitionRound::class, 'competition_id')->orderBy('sequence');
    }
    public function matches(): HasMany
    {
        return $this->hasMany(ActivityCompetitionMatch::class, 'competition_id');
    }
    public function judges(): HasMany
    {
        return $this->hasMany(ActivityCompetitionJudge::class, 'competition_id');
    }
    public function results(): HasMany
    {
        return $this->hasMany(ActivityCompetitionResult::class, 'competition_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
