<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\ProjectStatus;

/**
 * Proyek aktivitas (aplikasi, karya, riset kecil) yang dapat masuk portofolio.
 *
 * Tabel: `activity_projects`
 */
class ActivityProject extends ActivityModel
{
    protected $table = 'activity_projects';

    protected $casts = [
        'status' => ProjectStatus::class,
        'skills' => 'array',
        'started_on' => 'date',
        'completed_on' => 'date',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function period(): BelongsTo
    {
        return $this->belongsTo(ActivityPeriod::class, 'period_id');
    }
    public function members(): HasMany
    {
        return $this->hasMany(ActivityProjectMember::class, 'project_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
