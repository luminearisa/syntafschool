<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kerangka kompetensi (§29) — mis. "Programming" berisi Algorithm,
 * Data Structure, Problem Solving.
 */
class LmsCompetencyFramework extends BaseModel
{
    protected $table = 'lms_competency_frameworks';

    public function competencies(): HasMany
    {
        return $this->hasMany(LmsCompetency::class, 'framework_id')->orderBy('sort_order');
    }
}
