<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pivot program ↔ tujuan perkembangan.
 *
 * Tabel: `activity_program_objectives`
 */
class ActivityProgramObjective extends ActivityModel
{
    protected $table = 'activity_program_objectives';

    protected $casts = [
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function objective(): BelongsTo
    {
        return $this->belongsTo(ActivityObjective::class, 'objective_id');
    }
}
