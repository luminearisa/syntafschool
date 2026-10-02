<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model tabel `prayer_types`.
 */
class PrayerType extends Model
{
    protected $table = 'prayer_types';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'default_rakaat' => 'integer',
        'congregation_required' => 'boolean',
        'ramadan_only' => 'boolean',
        'sort_order' => 'integer',
        'active' => 'boolean',
        'config' => 'array',
    ];
}
