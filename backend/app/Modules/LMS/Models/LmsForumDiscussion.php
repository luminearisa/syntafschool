<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\ForumPostStatus;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Topik diskusi pada forum (§33). */
class LmsForumDiscussion extends BaseModel
{
    use LogsLmsActivity, ResolvesIdentity, SoftDeletes;

    protected $table = 'lms_forum_discussions';

    protected $casts = [
        'status' => ForumPostStatus::class,
        'is_pinned' => 'boolean',
        'is_locked' => 'boolean',
        'posts_count' => 'integer',
        'last_post_at' => 'datetime',
        'moderated_at' => 'datetime',
    ];

    public function forum(): BelongsTo
    {
        return $this->belongsTo(LmsForum::class, 'forum_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(LmsForumPost::class, 'discussion_id')->orderBy('created_at');
    }

    public function rootPosts(): HasMany
    {
        return $this->posts()->whereNull('parent_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', ForumPostStatus::VISIBLE->value);
    }

    public function isLocked(): bool
    {
        return (bool) $this->is_locked;
    }
}
