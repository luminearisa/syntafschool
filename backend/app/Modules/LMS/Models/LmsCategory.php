<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\CategoryStatus;
use App\Modules\LMS\Domain\Enums\CourseVisibility;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Kategori bertingkat: Category → Subcategory → Course (§4). */
class LmsCategory extends BaseModel
{
    use LogsLmsActivity, SoftDeletes;

    protected $table = 'lms_categories';

    protected $casts = [
        'status' => CategoryStatus::class,
        'visibility' => CourseVisibility::class,
        'sort_order' => 'integer',
        'depth' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(LmsCourse::class, 'category_id');
    }

    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CategoryStatus::ACTIVE->value);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('visibility', [
            CourseVisibility::PUBLIC->value,
            CourseVisibility::INSTITUTION->value,
        ]);
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_CATEGORY';
    }
}
