<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\ForumType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Konfigurasi forum diskusi (§32). */
class LmsForum extends BaseModel
{
    protected $table = 'lms_forums';

    protected $casts = [
        'forum_type' => ForumType::class,
        'subscription_enabled' => 'boolean',
        'moderation_enabled' => 'boolean',
        'grading_enabled' => 'boolean',
        'required_posts' => 'integer',
        'max_depth' => 'integer',
        'grading_max_posts' => 'integer',
        'settings' => 'array',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function discussions(): HasMany
    {
        return $this->hasMany(LmsForumDiscussion::class, 'forum_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(LmsForumSubscription::class, 'forum_id');
    }

    public function requiresModeration(): bool
    {
        return (bool) $this->moderation_enabled;
    }

    public function depthLimit(): int
    {
        return max(1, (int) $this->max_depth);
    }
}
