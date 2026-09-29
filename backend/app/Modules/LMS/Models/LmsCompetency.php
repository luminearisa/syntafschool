<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kompetensi dalam sebuah framework (§29). */
class LmsCompetency extends BaseModel
{
    protected $table = 'lms_competencies';

    public function framework(): BelongsTo
    {
        return $this->belongsTo(LmsCompetencyFramework::class, 'framework_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(LmsCourse::class, 'lms_course_competencies', 'competency_id', 'course_id')
            ->withPivot(['is_required', 'weight'])
            ->withTimestamps();
    }

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(LmsActivity::class, 'lms_activity_competencies', 'competency_id', 'activity_id')
            ->withPivot(['weight'])
            ->withTimestamps();
    }

    public function studentCompetencies(): HasMany
    {
        return $this->hasMany(LmsStudentCompetency::class, 'competency_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(LmsCompetencyEvidence::class, 'competency_id');
    }
}
