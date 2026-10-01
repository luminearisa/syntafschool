<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Keterampilan yang dibina aktivitas (kepemimpinan, kerja sama, pemrograman).
 *
 * Tabel: `activity_skills`
 */
class ActivitySkill extends ActivityModel
{
    protected $table = 'activity_skills';

    protected $casts = [
        'is_active' => 'boolean',
    ];
    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(ActivityProgram::class, 'activity_program_skills', 'skill_id', 'program_id')->withPivot('is_required', 'weight')->withTimestamps();
    }
    public function evidence(): HasMany
    {
        return $this->hasMany(ActivitySkillEvidence::class, 'skill_id');
    }
}
