<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kriteria rubric (§23). */
class LmsRubricCriterion extends BaseModel
{
    protected $table = 'lms_rubric_criteria';

    protected $casts = [
        'weight' => 'decimal:2',
        'maximum_score' => 'decimal:2',
    ];

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(LmsRubric::class, 'rubric_id');
    }

    public function levels(): HasMany
    {
        return $this->hasMany(LmsRubricLevel::class, 'criterion_id')->orderBy('sort_order');
    }

    /** Skor maksimum kriteria: eksplisit, atau level tertinggi. */
    public function maximumScore(): float
    {
        if ($this->maximum_score !== null) {
            return (float) $this->maximum_score;
        }

        $levels = $this->relationLoaded('levels') ? $this->levels : $this->levels()->get();

        return (float) ($levels->max('score') ?? 0);
    }

    /** @return array<string, mixed> */
    public function toStructure(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'weight' => (float) $this->weight,
            'maximum_score' => $this->maximumScore(),
            'sort_order' => (int) $this->sort_order,
            'levels' => $this->levels->map(fn (LmsRubricLevel $level) => [
                'id' => (int) $level->id,
                'name' => $level->name,
                'description' => $level->description,
                'score' => (float) $level->score,
                'sort_order' => (int) $level->sort_order,
            ])->all(),
        ];
    }
}
