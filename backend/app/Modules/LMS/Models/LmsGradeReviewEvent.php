<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jejak audit keputusan banding nilai (§53). */
class LmsGradeReviewEvent extends BaseModel
{
    protected $table = 'lms_grade_review_events';

    public $timestamps = false;

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(LmsGradeReview::class, 'grade_review_id');
    }
}
