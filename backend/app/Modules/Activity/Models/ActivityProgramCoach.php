<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pembina/pelatih yang ditugaskan pada satu program, beserta masa tugasnya.
 *
 * Tabel: `activity_program_coaches`
 */
class ActivityProgramCoach extends ActivityModel
{
    protected $table = 'activity_program_coaches';

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'employee_id');
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
