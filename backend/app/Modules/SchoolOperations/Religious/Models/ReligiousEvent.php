<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model tabel `religious_events`.
 */
class ReligiousEvent extends Model
{
    protected $table = 'religious_events';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'aggregate_id' => 'integer',
        'payload' => 'array',
        'actor_id' => 'integer',
        'occurred_at' => 'datetime',
        'published_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
