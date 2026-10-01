<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Kategori lomba (putra/putri, jenjang, batas peserta).
 *
 * Tabel: `activity_competition_categories`
 */
class ActivityCompetitionCategory extends ActivityModel
{
    protected $table = 'activity_competition_categories';

    protected $casts = [
        'maximum_participants' => 'integer',
        'maximum_team_size' => 'integer',
        'minimum_grade_level' => 'integer',
        'maximum_grade_level' => 'integer',
    ];
    public function competition(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetition::class, 'competition_id');
    }
    public function teams(): HasMany
    {
        return $this->hasMany(ActivityCompetitionTeam::class, 'category_id');
    }
    public function participants(): HasMany
    {
        return $this->hasMany(ActivityCompetitionParticipant::class, 'category_id');
    }
    public function rounds(): HasMany
    {
        return $this->hasMany(ActivityCompetitionRound::class, 'category_id');
    }
}
