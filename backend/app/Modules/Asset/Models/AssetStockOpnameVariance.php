<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_stock_opname_variances` — modul Asset & Sarpras.
 */
class AssetStockOpnameVariance extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_stock_opname_variances';

    protected $fillable = [
        'opname_id',
        'item_id',
        'asset_id',
        'variance_type',
        'description',
        'decision',
        'investigated_by',
        'decided_by',
        'decided_at',
        'adjustment',
        'notes',
    ];

    protected $casts = [
        'variance_type' => \App\Modules\Asset\Domain\Enums\StockOpnameScanResult::class,
        'decision' => \App\Modules\Asset\Domain\Enums\VarianceDecision::class,
        'decided_at' => 'datetime',
        'adjustment' => 'array',
    ];
}
