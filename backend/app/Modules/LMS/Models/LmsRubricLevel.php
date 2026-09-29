<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Level dalam sebuah kriteria rubric (mis. Excellent 4, Good 3, ...). */
class LmsRubricLevel extends BaseModel
{
    protected $table = 'lms_rubric_levels';

    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(LmsRubricCriterion::class, 'criterion_id');
    }
}
