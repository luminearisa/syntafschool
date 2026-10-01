<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Anggota tim penerima prestasi beserta hak kredit dan sertifikatnya.
 *
 * Tabel: `activity_achievement_members`
 */
class ActivityAchievementMember extends ActivityModel
{
    protected $table = 'activity_achievement_members';

    protected $casts = [
        'receives_credit' => 'boolean',
        'certificate_eligible' => 'boolean',
    ];
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(ActivityAchievement::class, 'achievement_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
}
