<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tujuan perkembangan yang dapat dipetakan ke banyak program.
 *
 * Tabel: `activity_objectives`
 */
class ActivityObjective extends ActivityModel
{
    protected $table = 'activity_objectives';

    protected $casts = [
        'is_active' => 'boolean',
    ];
    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(ActivityProgram::class, 'activity_program_objectives', 'objective_id', 'program_id')->withTimestamps();
    }
}
