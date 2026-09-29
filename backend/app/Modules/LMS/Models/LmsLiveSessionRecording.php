<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\LiveSessionProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rekaman pertemuan (§16). Bila provider tidak menyediakan progres tonton,
 * penyelesaian memakai penanda manual/`VIEW` biasa.
 */
class LmsLiveSessionRecording extends BaseModel
{
    protected $table = 'lms_live_session_recordings';

    protected $casts = [
        'provider' => LiveSessionProvider::class,
        'duration_seconds' => 'integer',
        'completion_threshold' => 'integer',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(LmsLiveSession::class, 'live_session_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'item_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
