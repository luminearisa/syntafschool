<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\ScoreStatus;

/**
 * Skor juri. Setelah dikirim dan dikunci, perubahan hanya lewat koreksi bersebab.
 *
 * Tabel: `activity_competition_scores`
 */
class ActivityCompetitionScore extends ActivityModel
{
    protected $table = 'activity_competition_scores';

    protected $casts = [
        'status' => ScoreStatus::class,
        'criteria_scores' => 'array',
        'total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'locked_at' => 'datetime',
        'corrected_at' => 'datetime',
    ];
    public function competition(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetition::class, 'competition_id');
    }
    public function match(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionMatch::class, 'match_id');
    }
    public function judge(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionJudge::class, 'judge_id');
    }
    public function participant(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionParticipant::class, 'participant_id');
    }
    public function correctedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrected_from_id');
    }
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'corrected_by');
    }
}
