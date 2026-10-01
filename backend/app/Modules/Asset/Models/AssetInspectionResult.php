<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_inspection_results` — modul Asset & Sarpras.
 */
class AssetInspectionResult extends Model
{
    protected $table = 'asset_inspection_results';

    protected $fillable = [
        'inspection_id',
        'code',
        'label',
        'result',
        'value',
        'notes',
    ];

    public function inspection(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetInspection::class, 'inspection_id');
    }

    protected $casts = [
        'result' => \App\Modules\Asset\Domain\Enums\InspectionItemResult::class,
    ];
}
