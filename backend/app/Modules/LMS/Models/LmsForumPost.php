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

/** Kiriman/balasan forum dengan sarang terbatas (§34). */
class LmsForumPost extends BaseModel
{
    use LogsLmsActivity, ResolvesIdentity, SoftDeletes;

    protected $table = 'lms_forum_posts';

    protected $casts = [
        'status' => ForumPostStatus::class,
        'depth' => 'integer',
        'is_teacher_post' => 'boolean',
        'edited_at' => 'datetime',
        'moderated_at' => 'datetime',
    ];

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(LmsForumDiscussion::class, 'discussion_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', ForumPostStatus::VISIBLE->value);
    }

    public function isPublicallyVisible(): bool
    {
        return $this->status->isPublic();
    }
}
