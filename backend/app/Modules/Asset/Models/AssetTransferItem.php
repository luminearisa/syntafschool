<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_transfer_items` — modul Asset & Sarpras.
 */
class AssetTransferItem extends Model
{
    protected $table = 'asset_transfer_items';

    protected $fillable = [
        'transfer_id',
        'asset_id',
        'condition_before',
        'condition_after',
        'notes',
    ];

    protected $casts = [
        'condition_before' => \App\Modules\Asset\Domain\Enums\AssetCondition::class,
        'condition_after' => \App\Modules\Asset\Domain\Enums\AssetCondition::class,
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
