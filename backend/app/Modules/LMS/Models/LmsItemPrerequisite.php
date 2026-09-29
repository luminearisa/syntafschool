<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\PrerequisiteType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dependency antar materi/aktivitas (§3).
 *
 * Baris di sini adalah *niat penulis konten*. Evaluasi tetap dilakukan mesin
 * completion Phase 1 (`CourseCompletionService`), sehingga tidak ada mesin
 * prasyarat kedua.
 */
class LmsItemPrerequisite extends BaseModel
{
    protected $table = 'lms_item_prerequisites';

    protected $casts = [
        'type' => PrerequisiteType::class,
        'threshold' => 'decimal:2',
        'release_at' => 'datetime',
        'is_enforced' => 'boolean',
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

    public function prerequisiteItem(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'prerequisite_item_id');
    }

    public function prerequisiteActivity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'prerequisite_activity_id');
    }

    /** Item yang dikunci baris ini (item atau aktivitas). */
    public function targetItemId(): ?int
    {
        if ($this->item_id !== null) {
            return (int) $this->item_id;
        }

        return $this->activity?->learning_item_id !== null ? (int) $this->activity->learning_item_id : null;
    }
}
