<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\ContentVisibility;
use App\Modules\LMS\Domain\Enums\ItemType;
use App\Modules\LMS\Domain\Enums\ResourceType;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Learning item (§12): RESOURCE (materi) atau ACTIVITY (aktivitas).
 * Phase 1 menyimpan konfigurasi aktivitas; eksekusinya menyusul pada phase
 * berikutnya melalui `external_activity_id` ke modul Assignment/Quiz.
 */
class LmsLearningItem extends BaseModel
{
    use LogsLmsActivity, SoftDeletes;

    protected $table = 'lms_learning_items';

    protected $casts = [
        'item_type' => ItemType::class,
        'resource_type' => ResourceType::class,
        'activity_type' => ActivityType::class,
        'completion_type' => CompletionType::class,
        'visibility' => ContentVisibility::class,
        'availability' => 'array',
        'is_required' => 'boolean',
        'is_preview' => 'boolean',
        'completion_min_grade' => 'decimal:2',
        'sort_order' => 'integer',
        'estimated_minutes' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(LmsCourseSection::class, 'section_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function resource(): HasOne
    {
        return $this->hasOne(LmsResource::class, 'learning_item_id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(LmsBookChapter::class, 'learning_item_id')->orderBy('sort_order');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(LmsActivityCompletion::class, 'learning_item_id');
    }

    public function isActivity(): bool
    {
        return $this->item_type === ItemType::ACTIVITY;
    }

    public function isResource(): bool
    {
        return $this->item_type === ItemType::RESOURCE;
    }

    public function isVisible(): bool
    {
        return $this->visibility === ContentVisibility::VISIBLE;
    }

    /** Aturan yang harus dipenuhi sebelum item dapat dibuka (§21). */
    public function availabilityRules(): array
    {
        $rules = $this->availability ?? [];

        return array_filter([
            'available_from' => $rules['available_from'] ?? null,
            'available_until' => $rules['available_until'] ?? null,
            'require_previous_item' => $rules['require_previous_item'] ?? null,
            'required_item_ids' => $rules['required_item_ids'] ?? [],
            'required_section_ids' => $rules['required_section_ids'] ?? [],
            'required_grade' => $rules['required_grade'] ?? null,
            'required_completion' => $rules['required_completion'] ?? null,
            'group_ids' => $rules['group_ids'] ?? [],
            'roles' => $rules['roles'] ?? [],
        ], fn ($value) => $value !== null && $value !== []);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visibility', ContentVisibility::VISIBLE->value);
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_ITEM';
    }
}
