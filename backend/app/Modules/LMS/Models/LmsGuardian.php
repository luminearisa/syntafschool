<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tautan orang tua ↔ anak pada Standalone Mode (§28). */
class LmsGuardian extends BaseModel
{
    protected $table = 'lms_guardians';

    protected $casts = ['is_primary' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(LmsUser::class, 'lms_user_id');
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(LmsLearner::class, 'lms_learner_id');
    }
}
