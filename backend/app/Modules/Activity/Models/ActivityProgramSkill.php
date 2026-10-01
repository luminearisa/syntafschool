<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pivot program ↔ keterampilan.
 *
 * Tabel: `activity_program_skills`
 */
class ActivityProgramSkill extends ActivityModel
{
    protected $table = 'activity_program_skills';

    protected $casts = [
        'is_required' => 'boolean',
        'weight' => 'decimal:2',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function skill(): BelongsTo
    {
        return $this->belongsTo(ActivitySkill::class, 'skill_id');
    }
}
