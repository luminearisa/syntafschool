<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\AnnouncementAudience;

/**
 * Pengumuman aktivitas untuk anggota, pembina, atau orang tua.
 *
 * Tabel: `activity_announcements`
 */
class ActivityAnnouncement extends ActivityModel
{
    protected $table = 'activity_announcements';

    protected $casts = [
        'audience' => AnnouncementAudience::class,
        'notify' => 'boolean',
        'attachments' => 'array',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
