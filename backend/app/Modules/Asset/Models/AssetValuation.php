<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_valuations` — modul Asset & Sarpras.
 */
class AssetValuation extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_valuations';

    protected $fillable = [
        'asset_id',
        'valuation_type',
        'amount',
        'previous_book_value',
        'effective_date',
        'reason',
        'document_reference',
        'approved_by',
        'approved_at',
        'created_by',
    ];

    protected $casts = [
        'valuation_type' => \App\Modules\Asset\Domain\Enums\ValuationType::class,
        'amount' => 'decimal:2',
        'previous_book_value' => 'decimal:2',
        'effective_date' => 'date',
        'approved_at' => 'datetime',
    ];
}
