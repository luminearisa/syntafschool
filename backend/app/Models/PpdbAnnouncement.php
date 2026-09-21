<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengumuman PPDB (draft → published → archived) yang tampil di portal/wali.
 */
class PpdbAnnouncement extends BaseModel
{
    use LogsPpdbActivity;

    public const CHANNEL_PORTAL = 'portal';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_ALL = 'all';

    public const CHANNELS = [
        self::CHANNEL_PORTAL, self::CHANNEL_EMAIL, self::CHANNEL_WHATSAPP, self::CHANNEL_ALL,
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    protected $casts = ['published_at' => 'datetime'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PpdbPeriod::class, 'period_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_ANNOUNCEMENT';
    }
}