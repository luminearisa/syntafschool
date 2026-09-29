<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\AggregationMethod;
use App\Modules\LMS\Domain\Enums\MissingGradePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori nilai (§4) — mendukung kategori bersarang (mis. Assessment →
 * Assignments → Tugas Harian).
 */
class LmsGradeCategory extends BaseModel
{
    protected $table = 'lms_grade_categories';

    protected $casts = [
        'weight' => 'decimal:2',
        'aggregation_method' => AggregationMethod::class,
        'missing_grade_policy' => MissingGradePolicy::class,
        'drop_lowest' => 'integer',
        'is_extra_credit' => 'boolean',
        'include_in_final' => 'boolean',
    ];

    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(LmsGradebook::class, 'gradebook_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LmsGradeItem::class, 'category_id')->orderBy('sort_order');
    }

    /** Metode agregasi efektif (kategori → gradebook). */
    public function effectiveAggregationMethod(?AggregationMethod $fallback = null): AggregationMethod
    {
        return $this->aggregation_method
            ?? $fallback
            ?? $this->gradebook?->aggregation_method
            ?? AggregationMethod::WEIGHTED_MEAN;
    }

    public function effectiveMissingPolicy(?MissingGradePolicy $fallback = null): MissingGradePolicy
    {
        return $this->missing_grade_policy
            ?? $fallback
            ?? $this->gradebook?->missing_grade_policy
            ?? MissingGradePolicy::PENDING;
    }
}
