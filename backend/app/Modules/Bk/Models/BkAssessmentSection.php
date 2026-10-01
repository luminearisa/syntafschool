<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Bagian di dalam template asesmen.
 *
 * Tabel: `bk_assessment_sections`
 */
class BkAssessmentSection extends BkModel
{
    protected $table = 'bk_assessment_sections';

    public function template(): BelongsTo
    {
        return $this->belongsTo(BkAssessmentTemplate::class, 'template_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(BkAssessmentQuestion::class, 'section_id');
    }
}
