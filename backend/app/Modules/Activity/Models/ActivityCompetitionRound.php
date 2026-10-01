<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\MatchStatus;
use App\Modules\Activity\Domain\Enums\RoundType;

/**
 * Babak kompetisi: kualifikasi, grup, perempat final, semifinal, final.
 *
 * Tabel: `activity_competition_rounds`
 */
class ActivityCompetitionRound extends ActivityModel
{
    protected $table = 'activity_competition_rounds';

    protected $casts = [
        'type' => RoundType::class,
        'status' => MatchStatus::class,
        'scheduled_date' => 'date',
        'sequence' => 'integer',
    ];
    public function competition(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetition::class, 'competition_id');
    }
    public function category(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionCategory::class, 'category_id');
    }
    public function matches(): HasMany
    {
        return $this->hasMany(ActivityCompetitionMatch::class, 'round_id')->orderBy('match_number');
    }
}
