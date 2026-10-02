<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `mosque_display_settings`.
 */
class MosqueDisplaySetting extends Model
{
    protected $table = 'mosque_display_settings';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'theme' => 'array',
        'show_current_prayer' => 'boolean',
        'show_next_prayer' => 'boolean',
        'show_iqamah_countdown' => 'boolean',
        'show_imam' => 'boolean',
        'show_muadzin' => 'boolean',
        'show_activities' => 'boolean',
        'show_announcements' => 'boolean',
        'announcement_ids' => 'array',
        'refresh_seconds' => 'integer',
        'active' => 'boolean',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }
}
