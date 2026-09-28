<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Profil siswa/guru pada Standalone Mode. */
class LmsLearner extends BaseModel
{
    protected $table = 'lms_learners';

    public const TYPE_STUDENT = 'STUDENT';

    public const TYPE_TEACHER = 'TEACHER';

    protected $casts = ['is_active' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(LmsUser::class, 'lms_user_id');
    }

    public function guardianLinks(): HasMany
    {
        return $this->hasMany(LmsGuardian::class, 'lms_learner_id');
    }
}
