<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Learning Outcome sebuah course (§26–§28).
 */
class LmsLearningOutcome extends BaseModel
{
    protected $table = 'lms_learning_outcomes';

    protected $casts = [
        'achievement_thresholds' => 'array',
        'is_required' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(
            LmsActivity::class,
            'lms_activity_outcomes',
            'learning_outcome_id',
            'activity_id',
        )->withPivot(['weight', 'mapped_by'])->withTimestamps();
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(LmsActivityOutcome::class, 'learning_outcome_id');
    }

    public function studentOutcomes(): HasMany
    {
        return $this->hasMany(LmsStudentOutcome::class, 'learning_outcome_id');
    }

    /**
     * Ambang batas capaian (§28) — configurable, fallback ke default sekolah.
     *
     * @return array{developing: float, achieved: float, mastered: float}
     */
    public function thresholds(): array
    {
        $configured = $this->achievement_thresholds ?? [];
        $global = (array) config('lms.outcome.thresholds', []);

        return [
            'developing' => (float) ($configured['developing'] ?? $global['developing'] ?? 60),
            'achieved' => (float) ($configured['achieved'] ?? $global['achieved'] ?? 75),
            'mastered' => (float) ($configured['mastered'] ?? $global['mastered'] ?? 90),
        ];
    }

    /** @return array<string, mixed> */
    public function toStructure(): array
    {
        return [
            'id' => (int) $this->id,
            'course_id' => (int) $this->course_id,
            'code' => $this->code,
            'statement' => $this->statement,
            'description' => $this->description,
            'category' => $this->category,
            'is_required' => (bool) $this->is_required,
            'sort_order' => (int) $this->sort_order,
            'status' => $this->status,
            'thresholds' => $this->thresholds(),
            'activity_ids' => $this->mappings->pluck('activity_id')->map(fn ($id) => (int) $id)->all(),
        ];
    }
}
