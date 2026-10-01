<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\ResultAward;
use App\Modules\Activity\Domain\Enums\ResultStatus;

/**
 * Hasil lomba yang diverifikasi lalu diterbitkan — dasar penerbitan prestasi.
 *
 * Tabel: `activity_competition_results`
 */
class ActivityCompetitionResult extends ActivityModel
{
    protected $table = 'activity_competition_results';

    protected $casts = [
        'status' => ResultStatus::class,
        'award' => ResultAward::class,
        'rank' => 'integer',
        'score' => 'decimal:2',
        'verified_at' => 'datetime',
        'published_at' => 'datetime',
    ];
    public function competition(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetition::class, 'competition_id');
    }
    public function category(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionCategory::class, 'category_id');
    }
    public function participant(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionParticipant::class, 'participant_id');
    }
    public function team(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionTeam::class, 'team_id');
    }
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'verified_by');
    }
}
