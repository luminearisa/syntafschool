<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_impairments` — modul Asset & Sarpras.
 */
class AssetImpairment extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_impairments';

    protected $fillable = [
        'asset_id',
        'amount',
        'reason',
        'effective_date',
        'approved_by',
        'finance_reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'effective_date' => 'date',
    ];
}
