<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Penugasan juri — cakupan tugasnya menentukan penampilan mana yang boleh dinilai.
 *
 * Tabel: `activity_competition_judges`
 */
class ActivityCompetitionJudge extends ActivityModel
{
    protected $table = 'activity_competition_judges';

    protected $casts = [
        'status' => 'string',
    ];
    public function competition(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetition::class, 'competition_id');
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'employee_id');
    }
    public function category(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionCategory::class, 'category_id');
    }
    public function round(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionRound::class, 'round_id');
    }
    public function scores(): HasMany
    {
        return $this->hasMany(ActivityCompetitionScore::class, 'judge_id');
    }
}
