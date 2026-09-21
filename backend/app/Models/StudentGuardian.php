<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGuardian extends BaseModel
{
    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function relation(): BelongsTo
    {
        return $this->belongsTo(GuardianRelation::class, 'guardian_relation_id');
    }
}
