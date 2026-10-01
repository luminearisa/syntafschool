<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_returns` — modul Asset & Sarpras.
 */
class AssetReturn extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_returns';

    protected $fillable = [
        'loan_id',
        'returned_at',
        'received_by',
        'condition',
        'accessories',
        'damage_found',
        'damage_report_id',
        'maintenance_request_id',
        'notes',
    ];

    protected $casts = [
        'condition' => \App\Modules\Asset\Domain\Enums\AssetCondition::class,
        'returned_at' => 'datetime',
        'accessories' => 'array',
        'damage_found' => 'boolean',
    ];
}
