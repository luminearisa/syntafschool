<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_stock_opnames` — modul Asset & Sarpras.
 */
class AssetStockOpname extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_stock_opnames';

    protected $fillable = [
        'opname_number',
        'title',
        'status',
        'scope',
        'method',
        'scheduled_for',
        'started_at',
        'finished_at',
        'started_by',
        'verified_by',
        'verified_at',
        'approved_by',
        'approved_at',
        'expected_count',
        'found_count',
        'variance_count',
        'notes',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\StockOpnameStatus::class,
        'scope' => 'array',
        'scheduled_for' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetStockOpnameItem::class, 'opname_id');
    }

    public function variances(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetStockOpnameVariance::class, 'opname_id');
    }

    public function starter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'started_by');
    }
}
