<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu entri item mutabaah harian.
 */
class TahfizMutabaahEntry extends BaseModel
{
    protected $casts = [
        'done' => 'boolean',
        'value' => 'decimal:2',
    ];

    public function mutabaah(): BelongsTo
    {
        return $this->belongsTo(TahfizMutabaah::class, 'mutabaah_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TahfizMutabaahItem::class, 'item_id');
    }
}
