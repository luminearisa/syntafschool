<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\AggregationMethod;
use App\Modules\LMS\Domain\Enums\ExtraCreditPolicy;
use App\Modules\LMS\Domain\Enums\GradebookStatus;
use App\Modules\LMS\Domain\Enums\GradingMethod;
use App\Modules\LMS\Domain\Enums\MissingGradePolicy;
use App\Modules\LMS\Domain\Enums\WeightPolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gradebook sebuah course (§2). Satu course = satu gradebook; nilai akhir
 * selalu dihitung dari grade item, tidak pernah disimpan sebagai angka lepas.
 */
class LmsGradebook extends BaseModel
{
    protected $table = 'lms_gradebooks';

    protected $casts = [
        'status' => GradebookStatus::class,
        'grading_method' => GradingMethod::class,
        'aggregation_method' => AggregationMethod::class,
        'missing_grade_policy' => MissingGradePolicy::class,
        'weight_policy' => WeightPolicy::class,
        'extra_credit_policy' => ExtraCreditPolicy::class,
        'maximum_grade' => 'decimal:2',
        'passing_grade' => 'decimal:2',
        'allow_extra_credit' => 'boolean',
        'is_parent_visible' => 'boolean',
        'is_ranking_visible' => 'boolean',
        'is_letter_visible' => 'boolean',
        'frozen_at' => 'datetime',
        'last_calculated_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(LmsGradeScale::class, 'grade_scale_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(LmsGradeCategory::class, 'gradebook_id')->orderBy('sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LmsGradeItem::class, 'gradebook_id')->orderBy('sort_order');
    }

    public function results(): HasMany
    {
        return $this->hasMany(LmsAssessmentResult::class, 'gradebook_id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(LmsGradeRelease::class, 'gradebook_id');
    }

    public function syncs(): HasMany
    {
        return $this->hasMany(LmsGradeSync::class, 'gradebook_id');
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null || $this->status === GradebookStatus::FROZEN;
    }

    /** Boleh menghitung/menyunting? FROZEN & LOCKED memerlukan izin khusus (§44). */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function passingGrade(): float
    {
        return (float) ($this->passing_grade ?? ($this->maximum_grade * 0.7));
    }

    /**
     * Hash konfigurasi bobot (§16): dipakai untuk mendeteksi apakah snapshot
     * rilis masih menggambarkan konfigurasi yang berlaku.
     */
    public function configHash(): string
    {
        $payload = [
            'aggregation' => $this->aggregation_method->value,
            'missing_policy' => $this->missing_grade_policy->value,
            'weight_policy' => $this->weight_policy->value,
            'extra_credit' => $this->extra_credit_policy->value,
            'maximum' => (float) $this->maximum_grade,
            'categories' => $this->categories->map(fn (LmsGradeCategory $c) => [
                'id' => (int) $c->id,
                'weight' => (float) $c->weight,
                'aggregation' => $c->aggregation_method?->value,
                'missing_policy' => $c->missing_grade_policy?->value,
                'parent' => $c->parent_id === null ? null : (int) $c->parent_id,
            ])->all(),
            'items' => $this->items->map(fn (LmsGradeItem $i) => [
                'id' => (int) $i->id,
                'weight' => (float) $i->weight,
                'category' => $i->category_id === null ? null : (int) $i->category_id,
                'maximum' => (float) $i->maximum_grade,
                'extra_credit' => (bool) $i->is_extra_credit,
                'include' => (bool) $i->include_in_final,
            ])->all(),
        ];

        return hash('sha256', json_encode($payload));
    }
}
