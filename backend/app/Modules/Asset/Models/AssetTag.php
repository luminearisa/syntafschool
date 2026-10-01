<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_tags` — modul Asset & Sarpras.
 */
class AssetTag extends Model
{
    protected $table = 'asset_tags';

    protected $fillable = [
        'asset_id',
        'tag_code',
        'status',
        'printed_at',
        'printed_by',
        'attached_at',
        'attached_by',
        'damaged_at',
        'replaced_tag_id',
        'notes',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\AssetTagStatus::class,
        'printed_at' => 'datetime',
        'attached_at' => 'datetime',
        'damaged_at' => 'datetime',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
