<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_notifications` — modul Asset & Sarpras.
 */
class AssetNotification extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_notifications';

    protected $fillable = [
        'event',
        'subject_type',
        'subject_id',
        'recipient_id',
        'recipient_role',
        'title',
        'body',
        'channel',
        'status',
        'sent_at',
        'read_at',
        'meta',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
        'meta' => 'array',
    ];
}
