<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\LearningPathMode;
use App\Modules\LMS\Domain\Enums\LearningPathStatus;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Learning path (§1–§2): urutan modul sebuah course.
 *
 * Path tidak menyimpan progress; ia hanya mengatur urutan dan aturan buka
 * modul, lalu menyerahkan evaluasi ke mesin availability yang sudah ada.
 */
class LmsLearningPath extends BaseModel
{
    use LogsLmsActivity, SoftDeletes;

    protected $table = 'lms_learning_paths';

    protected $casts = [
        'mode' => LearningPathMode::class,
        'status' => LearningPathStatus::class,
        'is_default' => 'boolean',
        'drip_enabled' => 'boolean',
        'sort_order' => 'integer',
        'settings' => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LmsLearningPathItem::class, 'learning_path_id')->orderBy('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', LearningPathStatus::ACTIVE->value);
    }

    public function isSequential(): bool
    {
        return $this->mode === LearningPathMode::SEQUENTIAL;
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_LEARNING_PATH';
    }
}
