<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prasyarat mata pelajaran: `subject_id` membutuhkan `prerequisite_subject_id`.
 */
class SubjectPrerequisite extends BaseModel
{
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'prerequisite_subject_id');
    }
}
