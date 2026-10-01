<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_loan_items` — modul Asset & Sarpras.
 */
class AssetLoanItem extends Model
{
    protected $table = 'asset_loan_items';

    protected $fillable = [
        'loan_id',
        'asset_id',
        'condition_out',
        'condition_in',
        'returned',
        'notes',
    ];

    protected $casts = [
        'condition_out' => \App\Modules\Asset\Domain\Enums\AssetCondition::class,
        'condition_in' => \App\Modules\Asset\Domain\Enums\AssetCondition::class,
        'returned' => 'boolean',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
