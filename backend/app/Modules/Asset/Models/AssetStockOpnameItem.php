<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Baris hasil hitung fisik. Selisih hidup di sini, bukan pada status aset.
 */
class AssetStockOpnameItem extends Model
{
    protected $table = 'asset_stock_opname_items';

    protected $fillable = [
        'opname_id',
        'asset_id',
        'expected_location_id',
        'expected_custodian_id',
        'scanned_at',
        'scanned_by',
        'scan_result',
        'actual_location_id',
        'actual_custodian_id',
        'condition_found',
        'is_variance',
        'variance_decision',
        'scanned_code',
        'adjustment_applied_at',
        'adjustment_notes',
        'notes',
    ];

    protected $casts = [
        'scan_result' => \App\Modules\Asset\Domain\Enums\StockOpnameScanResult::class,
        'variance_decision' => \App\Modules\Asset\Domain\Enums\VarianceDecision::class,
        'condition_found' => \App\Modules\Asset\Domain\Enums\AssetCondition::class,
        'scanned_at' => 'datetime',
        'is_variance' => 'boolean',
        'adjustment_applied_at' => 'datetime',
    ];

    public function opname(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetStockOpname::class, 'opname_id');
    }

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function expectedLocation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class, 'expected_location_id');
    }

    public function actualLocation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class, 'actual_location_id');
    }
}
