<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guardian extends BaseModel
{
    use SoftDeletes;

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class);
    }

    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class);
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    public function incomeRange(): BelongsTo
    {
        return $this->belongsTo(IncomeRange::class);
    }

    public function educationBackground(): BelongsTo
    {
        return $this->belongsTo(EducationBackground::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardians')
            ->withPivot(['guardian_relation_id', 'is_primary'])
            ->withTimestamps();
    }
}
