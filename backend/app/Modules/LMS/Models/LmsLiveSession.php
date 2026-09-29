<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\LiveSessionProvider;
use App\Modules\LMS\Domain\Enums\LiveSessionStatus;
use App\Modules\LMS\Domain\Enums\LiveSessionType;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pertemuan daring & jam konsultasi (§13–§17).
 */
class LmsLiveSession extends BaseModel
{
    use LogsLmsActivity, SoftDeletes;

    protected $table = 'lms_live_sessions';

    protected $casts = [
        'type' => LiveSessionType::class,
        'provider' => LiveSessionProvider::class,
        'status' => LiveSessionStatus::class,
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'capacity' => 'integer',
        'duration_minutes' => 'integer',
        'recurring' => 'boolean',
        'attendance_enabled' => 'boolean',
        'is_recorded' => 'boolean',
        'settings' => 'array',
        'published_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(LmsCourseSection::class, 'section_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(LmsLiveSessionAttendance::class, 'live_session_id');
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(LmsLiveSessionRecording::class, 'live_session_id');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('start_at', '>=', now())->orderBy('start_at');
    }

    public function scopeJoinable(Builder $query): Builder
    {
        return $query->whereIn('status', [LiveSessionStatus::SCHEDULED->value, LiveSessionStatus::LIVE->value]);
    }

    public function hasEnded(): bool
    {
        return $this->end_at !== null ? $this->end_at->isPast() : $this->start_at->isPast();
    }

    public function isJoinable(): bool
    {
        return $this->status?->isOpen() === true;
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_LIVE_SESSION';
    }
}
