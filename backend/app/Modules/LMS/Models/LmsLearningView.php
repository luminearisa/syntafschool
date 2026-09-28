<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat buka materi — bahan "Recently Viewed" (§33). */
class LmsLearningView extends BaseModel
{
    protected $table = 'lms_learning_views';

    protected $casts = ['viewed_at' => 'datetime'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'learning_item_id');
    }
}
