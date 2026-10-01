<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Activity\Domain\Enums\ActivityCategoryStatus;

/**
 * Kategori aktivitas berjenjang (Olahraga → Futsal, Seni → Musik).
 *
 * Tabel: `activity_categories`
 */
class ActivityCategory extends ActivityModel
{
    protected $table = 'activity_categories';

    use SoftDeletes;

    protected $casts = [
        'status' => ActivityCategoryStatus::class,
        'sort_order' => 'integer',
    ];
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
    public function programs(): HasMany
    {
        return $this->hasMany(ActivityProgram::class, 'category_id');
    }
}
