<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\LearningEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Peristiwa belajar ringan (§38). Tanpa observer global agar tidak ada
 * peristiwa yang tercatat tanpa makna.
 */
class LmsLearningEvent extends BaseModel
{
    protected $table = 'lms_learning_events';

    protected $casts = [
        'event_type' => LearningEventType::class,
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'item_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function scopeBetween(Builder $query, mixed $from, mixed $until): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $until]);
    }

    public function scopeOfType(Builder $query, LearningEventType $type): Builder
    {
        return $query->where('event_type', $type->value);
    }
}
