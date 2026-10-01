<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_depreciations` — modul Asset & Sarpras.
 */
class AssetDepreciation extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_depreciations';

    protected $fillable = [
        'asset_id',
        'period',
        'method',
        'amount',
        'accumulated_amount',
        'book_value',
        'status',
        'finance_reference',
        'finance_status',
        'posted_at',
        'posted_by',
        'notes',
    ];

    protected $casts = [
        'method' => \App\Modules\Asset\Domain\Enums\AssetDepreciationMethod::class,
        'amount' => 'decimal:2',
        'accumulated_amount' => 'decimal:2',
        'book_value' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
