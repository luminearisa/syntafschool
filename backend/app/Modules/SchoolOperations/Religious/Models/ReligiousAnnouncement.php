<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_announcements`.
 */
class ReligiousAnnouncement extends Model
{
    protected $table = 'religious_announcements';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'campus_id' => 'integer',
        'audience' => 'array',
        'publish_from' => 'datetime',
        'publish_until' => 'datetime',
        'created_by' => 'integer',
        'published_at' => 'datetime',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }
}
