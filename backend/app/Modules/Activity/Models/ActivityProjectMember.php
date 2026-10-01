<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Anggota proyek beserta kontribusinya.
 *
 * Tabel: `activity_project_members`
 */
class ActivityProjectMember extends ActivityModel
{
    protected $table = 'activity_project_members';

    protected $casts = [
    ];
    public function project(): BelongsTo
    {
        return $this->belongsTo(ActivityProject::class, 'project_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
}
