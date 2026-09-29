<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\InteractiveContentStatus;
use App\Modules\LMS\Domain\Enums\InteractiveContentType;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Konten interaktif (§7–§12).
 *
 * Satu model generik: bentuk isi setiap tipe berada di `content_data` dan
 * ditafsirkan oleh driver pada `InteractiveContentRegistry`.
 */
class LmsInteractiveContent extends BaseModel
{
    use LogsLmsActivity, SoftDeletes;

    protected $table = 'lms_interactive_contents';

    protected $casts = [
        'type' => InteractiveContentType::class,
        'status' => InteractiveContentStatus::class,
        'content_data' => 'array',
        'configuration' => 'array',
        'version' => 'integer',
        'is_reusable' => 'boolean',
        'required_interactions' => 'integer',
        'completion_min_slides' => 'integer',
        'completion_min_score' => 'decimal:2',
        'published_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'item_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LmsInteractiveContentVersion::class, 'interactive_content_id')->orderByDesc('version');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LmsInteractiveResponse::class, 'interactive_content_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', InteractiveContentStatus::PUBLISHED->value);
    }

    public function isPublished(): bool
    {
        return $this->status === InteractiveContentStatus::PUBLISHED;
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_INTERACTIVE';
    }
}
