<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\MatchStatus;

/**
 * Pertandingan/penampilan dalam satu babak.
 *
 * Tabel: `activity_competition_matches`
 */
class ActivityCompetitionMatch extends ActivityModel
{
    protected $table = 'activity_competition_matches';

    protected $casts = [
        'status' => MatchStatus::class,
        'scheduled_at' => 'datetime',
        'score_a' => 'decimal:2',
        'score_b' => 'decimal:2',
        'match_number' => 'integer',
    ];
    public function round(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionRound::class, 'round_id');
    }
    public function competition(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetition::class, 'competition_id');
    }
    public function participantA(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionParticipant::class, 'participant_a_id');
    }
    public function participantB(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionParticipant::class, 'participant_b_id');
    }
    public function winner(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionParticipant::class, 'winner_participant_id');
    }
    public function scores(): HasMany
    {
        return $this->hasMany(ActivityCompetitionScore::class, 'match_id');
    }
}
