<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris skala nilai (§6). */
class LmsGradeScaleEntry extends BaseModel
{
    protected $table = 'lms_grade_scale_entries';

    protected $casts = [
        'min_score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'gpa' => 'decimal:2',
    ];

    public function scale(): BelongsTo
    {
        return $this->belongsTo(LmsGradeScale::class, 'grade_scale_id');
    }

    public function matches(float $score): bool
    {
        return $score >= (float) $this->min_score && $score <= (float) $this->max_score;
    }
}
