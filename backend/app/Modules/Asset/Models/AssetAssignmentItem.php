<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_assignment_items` — modul Asset & Sarpras.
 */
class AssetAssignmentItem extends Model
{
    protected $table = 'asset_assignment_items';

    protected $fillable = [
        'assignment_id',
        'asset_id',
        'condition_before',
        'condition_after',
        'accessories',
        'notes',
    ];

    protected $casts = [
        'condition_before' => \App\Modules\Asset\Domain\Enums\AssetCondition::class,
        'condition_after' => \App\Modules\Asset\Domain\Enums\AssetCondition::class,
        'accessories' => 'array',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
