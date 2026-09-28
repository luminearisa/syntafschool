<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bab dari resource BOOK (§14). */
class LmsBookChapter extends BaseModel
{
    protected $table = 'lms_book_chapters';

    protected $casts = ['sort_order' => 'integer'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'learning_item_id');
    }
}
