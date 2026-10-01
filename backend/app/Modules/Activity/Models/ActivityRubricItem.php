<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Butir rubrik (algoritma, kerja sama, presentasi) beserta bobotnya.
 *
 * Tabel: `activity_rubric_items`
 */
class ActivityRubricItem extends ActivityModel
{
    protected $table = 'activity_rubric_items';

    protected $casts = [
        'weight' => 'decimal:2',
        'max_score' => 'decimal:2',
        'sort_order' => 'integer',
    ];
    public function rubric(): BelongsTo
    {
        return $this->belongsTo(ActivityRubric::class, 'rubric_id');
    }
}
