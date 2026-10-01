<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Usulan penggantian berdasarkan aturan yang dapat dikonfigurasi (bukan pesanan pembelian).
 */
class AssetReplacement extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_replacements';

    protected $fillable = [
        'asset_id',
        'recommendation',
        'trigger_reasons',
        'age_ratio',
        'maintenance_cost_ratio',
        'downtime_days',
        'priority',
        'status',
        'reviewed_by',
        'reviewed_at',
        'decision_notes',
        'procurement_reference',
        'replacement_asset_id',
        'generated_at',
    ];

    protected $casts = [
        'recommendation' => \App\Modules\Asset\Domain\Enums\ReplacementRecommendation::class,
        'trigger_reasons' => 'array',
        'age_ratio' => 'decimal:2',
        'maintenance_cost_ratio' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function reviewer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'reviewed_by');
    }
}
