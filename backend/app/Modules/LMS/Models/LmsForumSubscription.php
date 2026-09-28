<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Langganan notifikasi forum (§36). */
class LmsForumSubscription extends BaseModel
{
    use ResolvesIdentity;

    protected $table = 'lms_forum_subscriptions';

    protected $casts = [
        'notify_new_discussion' => 'boolean',
        'notify_new_post' => 'boolean',
    ];

    public function forum(): BelongsTo
    {
        return $this->belongsTo(LmsForum::class, 'forum_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }
}
